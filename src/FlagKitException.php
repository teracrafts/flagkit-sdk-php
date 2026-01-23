<?php

declare(strict_types=1);

namespace FlagKit;

use Exception;
use Throwable;

class FlagKitException extends Exception
{
    public function __construct(
        private readonly ErrorCode $errorCode,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct("[{$errorCode->value}] {$message}", 0, $previous);
    }

    public function getErrorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function isRecoverable(): bool
    {
        return $this->errorCode->isRecoverable();
    }

    public function isConfigError(): bool
    {
        return in_array($this->errorCode, [
            ErrorCode::ConfigInvalidUrl,
            ErrorCode::ConfigInvalidInterval,
            ErrorCode::ConfigMissingRequired,
            ErrorCode::ConfigInvalidApiKey,
            ErrorCode::ConfigInvalidBaseUrl,
            ErrorCode::ConfigInvalidPollingInterval,
            ErrorCode::ConfigInvalidCacheTtl,
        ], true);
    }

    public function isNetworkError(): bool
    {
        return in_array($this->errorCode, [
            ErrorCode::NetworkError,
            ErrorCode::NetworkTimeout,
            ErrorCode::NetworkRetryLimit,
            ErrorCode::HttpBadRequest,
            ErrorCode::HttpUnauthorized,
            ErrorCode::HttpForbidden,
            ErrorCode::HttpNotFound,
            ErrorCode::HttpRateLimited,
            ErrorCode::HttpServerError,
            ErrorCode::HttpTimeout,
            ErrorCode::HttpNetworkError,
            ErrorCode::HttpInvalidResponse,
            ErrorCode::HttpCircuitOpen,
        ], true);
    }

    public function isEvaluationError(): bool
    {
        return in_array($this->errorCode, [
            ErrorCode::EvalFlagNotFound,
            ErrorCode::EvalTypeMismatch,
            ErrorCode::EvalInvalidKey,
            ErrorCode::EvalInvalidValue,
            ErrorCode::EvalDisabled,
            ErrorCode::EvalError,
            ErrorCode::EvalContextError,
            ErrorCode::EvalDefaultUsed,
            ErrorCode::EvalStaleValue,
            ErrorCode::EvalCacheMiss,
            ErrorCode::EvalNetworkError,
            ErrorCode::EvalParseError,
            ErrorCode::EvalTimeoutError,
        ], true);
    }

    public static function configError(ErrorCode $code, string $message): self
    {
        return new self($code, $message);
    }

    public static function networkError(ErrorCode $code, string $message, ?Throwable $previous = null): self
    {
        return new self($code, $message, $previous);
    }

    public static function evaluationError(ErrorCode $code, string $message): self
    {
        return new self($code, $message);
    }

    public static function initError(string $message): self
    {
        return new self(ErrorCode::InitFailed, $message);
    }

    public static function notInitialized(): self
    {
        return new self(ErrorCode::SdkNotInitialized, 'SDK not initialized. Call FlagKit::initialize() first.');
    }

    public static function alreadyInitialized(): self
    {
        return new self(ErrorCode::SdkAlreadyInitialized, 'SDK already initialized.');
    }
}
