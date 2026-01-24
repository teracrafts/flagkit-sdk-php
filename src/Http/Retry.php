<?php

declare(strict_types=1);

namespace FlagKit\Http;

use FlagKit\Error\ErrorCode;
use FlagKit\Error\FlagKitException;

/**
 * Retry configuration for HTTP requests.
 */
class RetryConfig
{
    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const DEFAULT_BASE_DELAY_MS = 1000;
    public const DEFAULT_MAX_DELAY_MS = 30000;
    public const DEFAULT_BACKOFF_MULTIPLIER = 2.0;
    public const DEFAULT_JITTER_MS = 100;

    public function __construct(
        public readonly int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS,
        public readonly int $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        public readonly int $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
        public readonly float $backoffMultiplier = self::DEFAULT_BACKOFF_MULTIPLIER,
        public readonly int $jitterMs = self::DEFAULT_JITTER_MS
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            $maxAttempts,
            $this->baseDelayMs,
            $this->maxDelayMs,
            $this->backoffMultiplier,
            $this->jitterMs
        );
    }

    public function withBaseDelay(int $baseDelayMs): self
    {
        return new self(
            $this->maxAttempts,
            $baseDelayMs,
            $this->maxDelayMs,
            $this->backoffMultiplier,
            $this->jitterMs
        );
    }

    public function withMaxDelay(int $maxDelayMs): self
    {
        return new self(
            $this->maxAttempts,
            $this->baseDelayMs,
            $maxDelayMs,
            $this->backoffMultiplier,
            $this->jitterMs
        );
    }
}

/**
 * Result of a retry operation.
 *
 * @template T
 */
class RetryResult
{
    /**
     * @param T|null $value
     */
    public function __construct(
        public readonly bool $success,
        public readonly mixed $value = null,
        public readonly ?\Throwable $error = null,
        public readonly int $attempts = 0
    ) {
    }

    /**
     * @template V
     * @param V $value
     * @return RetryResult<V>
     */
    public static function success(mixed $value, int $attempts): self
    {
        return new self(true, $value, null, $attempts);
    }

    /**
     * @return RetryResult<null>
     */
    public static function failure(\Throwable $error, int $attempts): self
    {
        return new self(false, null, $error, $attempts);
    }
}

/**
 * Retry handler with exponential backoff and jitter.
 */
class Retry
{
    private RetryConfig $config;

    /** @var callable(\Throwable): bool|null */
    private $shouldRetryCallback = null;

    /** @var callable(int, \Throwable, int): void|null */
    private $onRetryCallback = null;

    public function __construct(?RetryConfig $config = null)
    {
        $this->config = $config ?? RetryConfig::default();
    }

    /**
     * Set a custom callback to determine if an error is retryable.
     *
     * @param callable(\Throwable): bool $callback
     */
    public function setShouldRetry(callable $callback): self
    {
        $this->shouldRetryCallback = $callback;
        return $this;
    }

    /**
     * Set a callback to be called before each retry attempt.
     *
     * @param callable(int, \Throwable, int): void $callback Receives (attempt, error, delayMs)
     */
    public function onRetry(callable $callback): self
    {
        $this->onRetryCallback = $callback;
        return $this;
    }

    /**
     * Execute an operation with retry logic.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws \Throwable
     */
    public function execute(callable $operation): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->config->maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                $lastException = $e;

                // Check if we should retry
                $canRetry = $this->shouldRetryCallback !== null
                    ? ($this->shouldRetryCallback)($e)
                    : $this->isRetryable($e);

                if (!$canRetry) {
                    throw $e;
                }

                // Check if we've exhausted retries
                if ($attempt >= $this->config->maxAttempts) {
                    throw $e;
                }

                // Calculate and apply backoff
                $delay = $this->calculateBackoff($attempt);

                // Call onRetry callback if provided
                if ($this->onRetryCallback !== null) {
                    ($this->onRetryCallback)($attempt, $e, $delay);
                }

                // Sleep before retrying (convert ms to microseconds)
                usleep($delay * 1000);
            }
        }

        throw $lastException ?? new \RuntimeException('Retry failed without exception');
    }

    /**
     * Execute an operation with retry logic, returning a result object instead of throwing.
     *
     * @template T
     * @param callable(): T $operation
     * @return RetryResult<T>
     */
    public function executeWithResult(callable $operation): RetryResult
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->config->maxAttempts; $attempt++) {
            try {
                $result = $operation();
                return RetryResult::success($result, $attempt);
            } catch (\Throwable $e) {
                $lastException = $e;

                // Check if we should retry
                $canRetry = $this->shouldRetryCallback !== null
                    ? ($this->shouldRetryCallback)($e)
                    : $this->isRetryable($e);

                if (!$canRetry || $attempt >= $this->config->maxAttempts) {
                    return RetryResult::failure($e, $attempt);
                }

                // Calculate and apply backoff
                $delay = $this->calculateBackoff($attempt);

                // Call onRetry callback if provided
                if ($this->onRetryCallback !== null) {
                    ($this->onRetryCallback)($attempt, $e, $delay);
                }

                // Sleep before retrying
                usleep($delay * 1000);
            }
        }

        return RetryResult::failure(
            $lastException ?? new \RuntimeException('Retry failed without exception'),
            $this->config->maxAttempts
        );
    }

    /**
     * Calculate backoff delay with exponential backoff and jitter.
     *
     * @param int $attempt Current attempt number (1-based)
     * @return int Delay in milliseconds
     */
    public function calculateBackoff(int $attempt): int
    {
        // Exponential backoff: baseDelay * (multiplier ^ (attempt - 1))
        $exponentialDelay = $this->config->baseDelayMs * pow(
            $this->config->backoffMultiplier,
            $attempt - 1
        );

        // Cap at maxDelay
        $cappedDelay = min((int) $exponentialDelay, $this->config->maxDelayMs);

        // Add jitter to prevent thundering herd
        $jitter = (int) (mt_rand() / mt_getrandmax() * $this->config->jitterMs);

        return $cappedDelay + $jitter;
    }

    /**
     * Determine if an error is retryable.
     */
    public function isRetryable(\Throwable $e): bool
    {
        if ($e instanceof FlagKitException) {
            return in_array($e->getErrorCode(), [
                ErrorCode::HttpTimeout,
                ErrorCode::HttpNetworkError,
                ErrorCode::HttpServerError,
                ErrorCode::HttpRateLimited,
                ErrorCode::NetworkError,
                ErrorCode::NetworkTimeout,
            ], true);
        }

        // Guzzle connection exceptions are retryable
        if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
            return true;
        }

        // Server errors (5xx) are retryable
        if ($e instanceof \GuzzleHttp\Exception\ServerException) {
            return true;
        }

        return false;
    }

    /**
     * Parse Retry-After header value.
     * Can be either a number of seconds or an HTTP date.
     *
     * @return int|null Delay in seconds, or null if invalid/not present
     */
    public static function parseRetryAfter(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Try parsing as number of seconds
        if (ctype_digit($value)) {
            $seconds = (int) $value;
            return $seconds > 0 ? $seconds : null;
        }

        // Try parsing as HTTP date
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            $now = time();
            $retryAt = $timestamp;
            if ($retryAt > $now) {
                return $retryAt - $now;
            }
        }

        return null;
    }
}

/**
 * Convenience function to execute with retry.
 *
 * @template T
 * @param callable(): T $operation
 * @return T
 */
function withRetry(callable $operation, ?RetryConfig $config = null): mixed
{
    return (new Retry($config))->execute($operation);
}
