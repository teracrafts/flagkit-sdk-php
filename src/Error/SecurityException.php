<?php

declare(strict_types=1);

namespace FlagKit\Error;

/**
 * Specific exception for security violations.
 */
class SecurityException extends FlagKitException
{
    public function __construct(ErrorCode $errorCode, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($errorCode, $message, $previous);
    }

    public static function piiDetected(string $fields): self
    {
        return new self(
            ErrorCode::SecurityPIIDetected,
            "PII detected without privateAttributes: {$fields}. " .
            'Either add these fields to privateAttributes or remove them. ' .
            'See: https://docs.flagkit.dev/sdk/security#pii-handling'
        );
    }

    public static function localPortInProduction(): self
    {
        return new self(
            ErrorCode::SecurityLocalPortInProduction,
            'localPort cannot be used in production environments. ' .
            'This option is only for local development. ' .
            'See: https://docs.flagkit.dev/sdk/security#local-development'
        );
    }

    public static function keyRotationFailed(string $message): self
    {
        return new self(
            ErrorCode::SecurityKeyRotationFailed,
            "Key rotation failed: {$message}"
        );
    }

    public static function encryptionFailed(string $message): self
    {
        return new self(
            ErrorCode::SecurityEncryptionFailed,
            "Encryption failed: {$message}"
        );
    }

    public static function decryptionFailed(string $message): self
    {
        return new self(
            ErrorCode::SecurityDecryptionFailed,
            "Decryption failed: {$message}"
        );
    }
}
