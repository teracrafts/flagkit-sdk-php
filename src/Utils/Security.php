<?php

declare(strict_types=1);

namespace FlagKit\Utils;

use Psr\Log\LoggerInterface;

/**
 * Security configuration options
 */
class SecurityConfig
{
    /**
     * @param bool $warnOnPotentialPII Warn about potential PII in context/events
     * @param bool $warnOnServerKeyInBrowser Warn when server keys are used in browser
     * @param string[] $additionalPIIPatterns Custom PII patterns to detect
     */
    public function __construct(
        public readonly bool $warnOnPotentialPII = true,
        public readonly bool $warnOnServerKeyInBrowser = true,
        /** @var string[] */
        public readonly array $additionalPIIPatterns = []
    ) {
    }

    /**
     * Create default configuration
     */
    public static function default(): self
    {
        return new self();
    }

    /**
     * Create configuration with PII warnings disabled (for production)
     */
    public static function production(): self
    {
        return new self(warnOnPotentialPII: false);
    }
}

/**
 * Security utilities for FlagKit SDK
 */
class Security
{
    /**
     * Common PII field patterns (case-insensitive)
     */
    private const PII_PATTERNS = [
        'email',
        'phone',
        'telephone',
        'mobile',
        'ssn',
        'social_security',
        'socialSecurity',
        'credit_card',
        'creditCard',
        'card_number',
        'cardNumber',
        'cvv',
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apiKey',
        'private_key',
        'privateKey',
        'access_token',
        'accessToken',
        'refresh_token',
        'refreshToken',
        'auth_token',
        'authToken',
        'address',
        'street',
        'zip_code',
        'zipCode',
        'postal_code',
        'postalCode',
        'date_of_birth',
        'dateOfBirth',
        'dob',
        'birth_date',
        'birthDate',
        'passport',
        'driver_license',
        'driverLicense',
        'national_id',
        'nationalId',
        'bank_account',
        'bankAccount',
        'routing_number',
        'routingNumber',
        'iban',
        'swift',
    ];

    /**
     * Additional custom PII patterns
     *
     * @var string[]
     */
    private static array $additionalPatterns = [];

    /**
     * Set additional PII patterns to check
     *
     * @param string[] $patterns
     */
    public static function setAdditionalPatterns(array $patterns): void
    {
        self::$additionalPatterns = $patterns;
    }

    /**
     * Get all PII patterns (built-in + additional)
     *
     * @return string[]
     */
    public static function getAllPatterns(): array
    {
        return array_merge(self::PII_PATTERNS, self::$additionalPatterns);
    }

    /**
     * Check if a field name potentially contains PII
     */
    public static function isPotentialPIIField(string $fieldName): bool
    {
        $lowerName = strtolower($fieldName);

        foreach (self::getAllPatterns() as $pattern) {
            if (str_contains($lowerName, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect potential PII in an array and return the field paths
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    public static function detectPotentialPII(array $data, string $prefix = ''): array
    {
        $piiFields = [];

        foreach ($data as $key => $value) {
            $fullPath = $prefix !== '' ? "{$prefix}.{$key}" : (string) $key;

            if (self::isPotentialPIIField((string) $key)) {
                $piiFields[] = $fullPath;
            }

            // Recursively check nested arrays (associative only)
            if (is_array($value) && self::isAssociativeArray($value)) {
                $nestedPII = self::detectPotentialPII($value, $fullPath);
                $piiFields = array_merge($piiFields, $nestedPII);
            }
        }

        return $piiFields;
    }

    /**
     * Warn about potential PII in data
     *
     * @param array<string, mixed>|null $data
     */
    public static function warnIfPotentialPII(
        ?array $data,
        string $dataType,
        ?LoggerInterface $logger
    ): void {
        if ($data === null || $logger === null) {
            return;
        }

        $piiFields = self::detectPotentialPII($data);

        if (count($piiFields) > 0) {
            $fieldsStr = implode(', ', $piiFields);
            $advice = $dataType === 'context'
                ? 'Consider adding these to privateAttributes.'
                : 'Consider removing sensitive data from events.';

            $logger->warning(
                "[FlagKit Security] Potential PII detected in {$dataType} data: {$fieldsStr}. {$advice}"
            );
        }
    }

    /**
     * Check if an API key is a server key
     */
    public static function isServerKey(string $apiKey): bool
    {
        return str_starts_with($apiKey, 'srv_');
    }

    /**
     * Check if an API key is a client/SDK key
     */
    public static function isClientKey(string $apiKey): bool
    {
        return str_starts_with($apiKey, 'sdk_') || str_starts_with($apiKey, 'cli_');
    }

    /**
     * Warn if server key is used in browser environment
     *
     * Note: In PHP, browser detection is based on SAPI name and common browser indicators.
     * This primarily applies to PHP running in a context that might expose keys to browsers.
     */
    public static function warnIfServerKeyInBrowser(string $apiKey, ?LoggerInterface $logger): void
    {
        if (self::isBrowserEnvironment() && self::isServerKey($apiKey)) {
            $message = '[FlagKit Security] WARNING: Server keys (srv_) should not be used in browser environments. ' .
                'This exposes your server key in client-side code, which is a security risk. ' .
                'Use SDK keys (sdk_) for client-side applications instead. ' .
                'See: https://docs.flagkit.dev/sdk/security#api-keys';

            // Log to error_log for visibility
            error_log($message);

            // Also log through the SDK logger if available
            $logger?->warning($message);
        }
    }

    /**
     * Check if running in a browser-like environment
     *
     * For PHP, this checks if we're running in a web context where
     * the API key might be exposed to the browser (e.g., in JavaScript output)
     */
    public static function isBrowserEnvironment(): bool
    {
        // Check if running as a web server (not CLI)
        if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
            return false;
        }

        // Check for common browser request indicators
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);
            $browserPatterns = ['mozilla', 'chrome', 'safari', 'firefox', 'edge', 'opera'];

            foreach ($browserPatterns as $pattern) {
                if (str_contains($userAgent, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if an array is associative (has string keys)
     *
     * @param array<mixed> $array
     */
    private static function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
