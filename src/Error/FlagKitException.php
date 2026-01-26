<?php

declare(strict_types=1);

namespace FlagKit\Error;

use Exception;
use Throwable;

class FlagKitException extends Exception
{
    /**
     * Global sanitization settings (set by FlagKit client).
     */
    private static bool $sanitizationEnabled = true;
    private static bool $preserveOriginal = false;

    /**
     * The original unsanitized message (if preservation is enabled).
     */
    private ?string $originalMessage = null;

    public function __construct(
        private readonly ErrorCode $errorCode,
        string $message,
        ?Throwable $previous = null
    ) {
        $sanitizedMessage = ErrorSanitizer::sanitizeWithPreservation(
            $message,
            self::$sanitizationEnabled,
            self::$preserveOriginal
        );

        if (self::$preserveOriginal) {
            $this->originalMessage = $message;
        }

        parent::__construct("[{$errorCode->value}] {$sanitizedMessage}", 0, $previous);
    }

    /**
     * Configure global sanitization settings.
     *
     * @param bool $enabled Whether to enable sanitization
     * @param bool $preserveOriginal Whether to preserve original messages
     */
    public static function configureSanitization(bool $enabled = true, bool $preserveOriginal = false): void
    {
        self::$sanitizationEnabled = $enabled;
        self::$preserveOriginal = $preserveOriginal;
    }

    /**
     * Get the original unsanitized message (if preservation was enabled).
     *
     * @return string|null The original message, or null if not preserved
     */
    public function getOriginalMessage(): ?string
    {
        return $this->originalMessage;
    }

    /**
     * Check if sanitization is currently enabled.
     *
     * @return bool True if sanitization is enabled
     */
    public static function isSanitizationEnabled(): bool
    {
        return self::$sanitizationEnabled;
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

    public static function securityError(ErrorCode $code, string $message): self
    {
        return new self($code, $message);
    }

    public function isSecurityError(): bool
    {
        return in_array($this->errorCode, [
            ErrorCode::SecurityError,
            ErrorCode::SecurityPIIDetected,
            ErrorCode::SecurityLocalPortInProduction,
            ErrorCode::SecurityKeyRotationFailed,
            ErrorCode::SecurityEncryptionFailed,
            ErrorCode::SecurityDecryptionFailed,
        ], true);
    }
}
