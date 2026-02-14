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

    public static function bootstrapVerificationFailed(string $message): self
    {
        return new self(
            ErrorCode::SecurityBootstrapVerificationFailed,
            "Bootstrap verification failed: {$message}. " .
            'The bootstrap data may have been tampered with or is expired. ' .
            'See: https://docs.flagkit.dev/sdk/security#bootstrap-verification'
        );
    }
}
