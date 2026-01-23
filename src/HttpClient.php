<?php

declare(strict_types=1);

namespace FlagKit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\RequestException;

class HttpClient
{
    private Client $client;
    private CircuitBreaker $circuitBreaker;

    public function __construct(
        private readonly FlagKitOptions $options
    ) {
        $this->client = new Client([
            'base_uri' => $options->baseUrl,
            'timeout' => $options->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $options->apiKey,
                'X-API-Key' => $options->apiKey,
                'User-Agent' => 'FlagKit-PHP/1.0.0',
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        $this->circuitBreaker = new CircuitBreaker(
            $options->circuitBreakerThreshold,
            $options->circuitBreakerResetTimeout
        );
    }

    /**
     * @template T
     * @param class-string<T>|null $responseClass
     * @return T|array<string, mixed>
     */
    public function get(string $path, ?string $responseClass = null): mixed
    {
        return $this->executeWithRetry(function () use ($path, $responseClass) {
            $response = $this->client->get($path);
            return $this->handleResponse($response->getBody()->getContents(), $responseClass);
        });
    }

    /**
     * @template T
     * @param array<string, mixed> $body
     * @param class-string<T>|null $responseClass
     * @return T|array<string, mixed>
     */
    public function post(string $path, array $body, ?string $responseClass = null): mixed
    {
        return $this->executeWithRetry(function () use ($path, $body, $responseClass) {
            $response = $this->client->post($path, [
                'json' => $body,
            ]);
            return $this->handleResponse($response->getBody()->getContents(), $responseClass);
        });
    }

    /**
     * @template T
     * @param callable(): T $action
     * @return T
     */
    private function executeWithRetry(callable $action): mixed
    {
        return $this->circuitBreaker->execute(function () use ($action) {
            $lastException = null;

            for ($attempt = 0; $attempt <= $this->options->retryAttempts; $attempt++) {
                try {
                    return $action();
                } catch (\Throwable $e) {
                    $lastException = $e;

                    if (!$this->isRetryable($e) || $attempt >= $this->options->retryAttempts) {
                        throw $this->convertException($e);
                    }

                    $delay = $this->calculateBackoff($attempt);
                    usleep((int) ($delay * 1000));
                }
            }

            throw $lastException ?? new \RuntimeException('Retry failed without exception');
        });
    }

    private function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof ConnectException) {
            return true;
        }

        if ($e instanceof ServerException) {
            return true;
        }

        if ($e instanceof FlagKitException) {
            return in_array($e->getErrorCode(), [
                ErrorCode::HttpTimeout,
                ErrorCode::HttpNetworkError,
                ErrorCode::HttpServerError,
            ], true);
        }

        return false;
    }

    private function calculateBackoff(int $attempt): float
    {
        $baseDelay = 1000.0;
        $maxDelay = 30000.0;
        $multiplier = 2.0;

        $delay = $baseDelay * pow($multiplier, $attempt);
        $delay = min($delay, $maxDelay);

        // Add jitter (0-25%)
        $jitter = $delay * 0.25 * (mt_rand() / mt_getrandmax());
        $delay += $jitter;

        return $delay;
    }

    /**
     * @template T
     * @param class-string<T>|null $responseClass
     * @return T|array<string, mixed>
     */
    private function handleResponse(string $content, ?string $responseClass): mixed
    {
        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw FlagKitException::networkError(
                ErrorCode::HttpInvalidResponse,
                'Failed to parse JSON response: ' . json_last_error_msg()
            );
        }

        if ($responseClass !== null && method_exists($responseClass, 'fromArray')) {
            return $responseClass::fromArray($data);
        }

        return $data;
    }

    private function convertException(\Throwable $e): FlagKitException
    {
        if ($e instanceof FlagKitException) {
            return $e;
        }

        if ($e instanceof ConnectException) {
            return FlagKitException::networkError(
                ErrorCode::HttpNetworkError,
                'Network error: ' . $e->getMessage(),
                $e
            );
        }

        if ($e instanceof ClientException) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = $e->getResponse()->getBody()->getContents();

            [$errorCode, $category] = match ($statusCode) {
                400 => [ErrorCode::HttpBadRequest, 'Client Error'],
                401 => [ErrorCode::HttpUnauthorized, 'Authentication Error'],
                403 => [ErrorCode::HttpForbidden, 'Authorization Error'],
                404 => [ErrorCode::HttpNotFound, 'Not Found'],
                429 => [ErrorCode::HttpRateLimited, 'Rate Limited'],
                default => [ErrorCode::HttpBadRequest, 'Client Error'],
            };

            return FlagKitException::networkError(
                $errorCode,
                "{$category}: {$statusCode} - {$body}",
                $e
            );
        }

        if ($e instanceof ServerException) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = $e->getResponse()->getBody()->getContents();

            return FlagKitException::networkError(
                ErrorCode::HttpServerError,
                "Server Error: {$statusCode} - {$body}",
                $e
            );
        }

        if ($e instanceof RequestException) {
            return FlagKitException::networkError(
                ErrorCode::HttpNetworkError,
                'Request error: ' . $e->getMessage(),
                $e
            );
        }

        return FlagKitException::networkError(
            ErrorCode::HttpNetworkError,
            'Unexpected error: ' . $e->getMessage(),
            $e
        );
    }
}
