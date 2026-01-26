<?php

declare(strict_types=1);

namespace FlagKit\Error;

/**
 * Sanitizes error messages to prevent information leakage.
 *
 * Removes sensitive information like:
 * - File system paths (Unix and Windows)
 * - IP addresses
 * - API keys (sdk_, srv_, cli_ prefixes)
 * - Email addresses
 * - Database connection strings
 */
class ErrorSanitizer
{
    /**
     * Patterns to match sensitive information and their replacements.
     * @var array<string, string>
     */
    private const PATTERNS = [
        // Unix file paths (negative lookbehind to exclude URLs like https://... and http://...)
        '/(?<![:\/\w])\/(?:[\w.-]+\/)+[\w.-]+/' => '[PATH]',
        // Windows file paths
        '/[A-Za-z]:\\\\(?:[\w\s.-]+\\\\)+[\w.-]*/' => '[PATH]',
        // IP addresses (IPv4)
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/' => '[IP]',
        // SDK API keys
        '/sdk_[a-zA-Z0-9_-]{8,}/' => 'sdk_[REDACTED]',
        // Server API keys
        '/srv_[a-zA-Z0-9_-]{8,}/' => 'srv_[REDACTED]',
        // CLI API keys
        '/cli_[a-zA-Z0-9_-]{8,}/' => 'cli_[REDACTED]',
        // Email addresses
        '/[\w.+-]+@[\w.-]+\.\w+/' => '[EMAIL]',
        // Database connection strings (postgres, mysql, mongodb, redis)
        '/(?:postgres|mysql|mongodb|redis):\/\/[^\s]+/i' => '[CONNECTION_STRING]',
    ];

    /**
     * The original unsanitized message (if preservation is enabled).
     */
    private static ?string $lastOriginalMessage = null;

    /**
     * Sanitize an error message by removing sensitive information.
     *
     * @param string $message The message to sanitize
     * @param bool $enabled Whether sanitization is enabled (default: true)
     * @return string The sanitized message
     */
    public static function sanitize(string $message, bool $enabled = true): string
    {
        if (!$enabled) {
            return $message;
        }

        $sanitized = $message;

        foreach (self::PATTERNS as $pattern => $replacement) {
            $sanitized = preg_replace($pattern, $replacement, $sanitized) ?? $sanitized;
        }

        return $sanitized;
    }

    /**
     * Sanitize a message and optionally preserve the original.
     *
     * @param string $message The message to sanitize
     * @param bool $enabled Whether sanitization is enabled
     * @param bool $preserveOriginal Whether to store the original message
     * @return string The sanitized message
     */
    public static function sanitizeWithPreservation(
        string $message,
        bool $enabled = true,
        bool $preserveOriginal = false
    ): string {
        if ($preserveOriginal) {
            self::$lastOriginalMessage = $message;
        } else {
            self::$lastOriginalMessage = null;
        }

        return self::sanitize($message, $enabled);
    }

    /**
     * Get the last original (unsanitized) message if preservation was enabled.
     *
     * @return string|null The original message, or null if not preserved
     */
    public static function getLastOriginalMessage(): ?string
    {
        return self::$lastOriginalMessage;
    }

    /**
     * Clear the stored original message.
     */
    public static function clearOriginalMessage(): void
    {
        self::$lastOriginalMessage = null;
    }

    /**
     * Check if a message contains potentially sensitive information.
     *
     * @param string $message The message to check
     * @return bool True if sensitive information was detected
     */
    public static function containsSensitiveInfo(string $message): bool
    {
        foreach (self::PATTERNS as $pattern => $replacement) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get all patterns used for sanitization.
     *
     * @return array<string, string> Array of pattern => replacement pairs
     */
    public static function getPatterns(): array
    {
        return self::PATTERNS;
    }
}
