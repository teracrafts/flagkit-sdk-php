<?php

declare(strict_types=1);

namespace FlagKit\Http;

use FlagKit\Error\ErrorCode;
use FlagKit\Error\FlagKitException;
use FlagKit\FlagKitOptions;
use FlagKit\Utils\Security;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

class HttpClient
{
    private const BASE_URL = 'https://api.flagkit.dev/api/v1';
    private const SDK_VERSION = '1.0.2';

    private Client $client;
    private CircuitBreaker $circuitBreaker;
    private string $currentApiKey;
    private ?int $keyRotationTimestamp = null;
    private ?LoggerInterface $logger = null;

    /**
     * Returns the base URL for the given local port, or the default production URL.
     */
    public static function getBaseUrl(?int $localPort): string
    {
        if ($localPort !== null) {
            return "http://localhost:{$localPort}/api/v1";
        }
        return self::BASE_URL;
    }

    public function __construct(
        private readonly FlagKitOptions $options,
        ?LoggerInterface $logger = null
    ) {
        $this->currentApiKey = $options->apiKey;
        $this->logger = $logger;

        $this->client = new Client([
            'base_uri' => self::getBaseUrl($options->localPort),
            'timeout' => $options->timeout,
        ]);

        $this->circuitBreaker = new CircuitBreaker(
            $options->circuitBreakerThreshold,
            $options->circuitBreakerResetTimeout
        );
    }

    /**
     * Get the current active API key
     */
    public function getActiveApiKey(): string
    {
        return $this->currentApiKey;
    }

    /**
     * Get the key identifier (first 8 chars) for the current key
     */
    public function getKeyId(): string
    {
        return Security::getKeyId($this->currentApiKey);
    }

    /**
     * Check if key rotation is currently active
     */
    public function isInKeyRotation(): bool
    {
        if ($this->keyRotationTimestamp === null) {
            return false;
        }

        $elapsed = time() - $this->keyRotationTimestamp;
        return $elapsed < $this->options->keyRotationGracePeriod;
    }

    /**
     * Rotate to secondary API key
     *
     * @return bool True if rotation was performed
     */
    private function rotateToSecondaryKey(): bool
    {
        if ($this->options->secondaryApiKey === null) {
            return false;
        }

        if ($this->currentApiKey === $this->options->secondaryApiKey) {
            // Already using secondary key
            return false;
        }

        $this->logger?->info('Rotating to secondary API key due to authentication failure');
        $this->currentApiKey = $this->options->secondaryApiKey;
        $this->keyRotationTimestamp = time();
        return true;
    }

    /**
     * Build request headers
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'X-API-Key' => $this->currentApiKey,
            'User-Agent' => 'FlagKit-PHP/' . self::SDK_VERSION,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-FlagKit-SDK-Version' => self::SDK_VERSION,
            'X-FlagKit-SDK-Language' => 'php',
        ];
    }

    /**
     * @template T
     * @param class-string<T>|null $responseClass
     * @return T|array<string, mixed>
     */
    public function get(string $path, ?string $responseClass = null): mixed
    {
        return $this->executeWithKeyRotation(function () use ($path, $responseClass) {
            return $this->executeWithRetry(function () use ($path, $responseClass) {
                $response = $this->client->get($path, [
                    'headers' => $this->buildHeaders(),
                ]);
                return $this->handleResponse($response->getBody()->getContents(), $responseClass);
            });
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
        return $this->executeWithKeyRotation(function () use ($path, $body, $responseClass) {
            return $this->executeWithRetry(function () use ($path, $body, $responseClass) {
                $headers = $this->buildHeaders();

                // Add signing headers for POST requests if enabled
                if ($this->options->enableRequestSigning) {
                    $bodyJson = json_encode($body, JSON_THROW_ON_ERROR);
                    $signingData = Security::createRequestSignature($bodyJson, $this->currentApiKey);
                    $headers['X-Signature'] = $signingData['signature'];
                    $headers['X-Timestamp'] = (string) $signingData['timestamp'];
                    $headers['X-Key-Id'] = $this->getKeyId();
                }

                $response = $this->client->post($path, [
                    'headers' => $headers,
                    'json' => $body,
                ]);
                return $this->handleResponse($response->getBody()->getContents(), $responseClass);
            });
        });
    }

    /**
     * Execute with key rotation support
     *
     * @template T
     * @param callable(): T $action
     * @return T
     */
    private function executeWithKeyRotation(callable $action): mixed
    {
        try {
            return $action();
        } catch (FlagKitException $e) {
            // Handle 401 errors with key rotation
            if ($e->getErrorCode() === ErrorCode::HttpUnauthorized && $this->options->secondaryApiKey !== null) {
                $rotated = $this->rotateToSecondaryKey();
                if ($rotated) {
                    $this->logger?->debug('Retrying request with secondary API key');
                    return $action();
                }
            }
            throw $e;
        }
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
