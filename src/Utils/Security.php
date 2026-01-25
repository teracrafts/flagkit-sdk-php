<?php

declare(strict_types=1);

namespace FlagKit\Utils;

use FlagKit\Error\ErrorCode;
use FlagKit\Error\SecurityException;
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
 * PII detection result
 */
class PIIDetectionResult
{
    /**
     * @param bool $hasPII Whether PII was detected
     * @param string[] $fields List of fields containing potential PII
     * @param string $message Warning message
     */
    public function __construct(
        public readonly bool $hasPII,
        public readonly array $fields,
        public readonly string $message
    ) {
    }

    /**
     * Create result indicating no PII was found
     */
    public static function noPII(): self
    {
        return new self(false, [], '');
    }
}

/**
 * Signed payload structure for requests
 *
 * @template T
 */
class SignedPayload
{
    /**
     * @param T $data The payload data
     * @param string $signature HMAC-SHA256 signature
     * @param int $timestamp Unix timestamp in milliseconds
     * @param string $keyId First 8 characters of the API key
     */
    public function __construct(
        public readonly mixed $data,
        public readonly string $signature,
        public readonly int $timestamp,
        public readonly string $keyId
    ) {
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'signature' => $this->signature,
            'timestamp' => $this->timestamp,
            'keyId' => $this->keyId,
        ];
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

    /**
     * Check for potential PII and return detailed result
     *
     * @param array<string, mixed>|null $data
     */
    public static function checkForPotentialPII(?array $data, string $dataType): PIIDetectionResult
    {
        if ($data === null) {
            return PIIDetectionResult::noPII();
        }

        $piiFields = self::detectPotentialPII($data);

        if (count($piiFields) === 0) {
            return PIIDetectionResult::noPII();
        }

        $fieldsStr = implode(', ', $piiFields);
        $advice = $dataType === 'context'
            ? 'Consider adding these to privateAttributes.'
            : 'Consider removing sensitive data from events.';

        $message = "[FlagKit Security] Potential PII detected in {$dataType} data: {$fieldsStr}. {$advice}";

        return new PIIDetectionResult(true, $piiFields, $message);
    }

    /**
     * Check if environment is production
     */
    public static function isProductionEnvironment(): bool
    {
        $appEnv = getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? null);
        return $appEnv === 'production';
    }

    /**
     * Get the first 8 characters of an API key for identification
     * This is safe to expose as it doesn't reveal the full key
     */
    public static function getKeyId(string $apiKey): string
    {
        return substr($apiKey, 0, 8);
    }

    /**
     * Generate HMAC-SHA256 signature
     */
    public static function generateHMACSHA256(string $message, string $key): string
    {
        return hash_hmac('sha256', $message, $key);
    }

    /**
     * Sign a payload with HMAC-SHA256
     *
     * @template T
     * @param T $data
     * @return SignedPayload<T>
     */
    public static function signPayload(mixed $data, string $apiKey, ?int $timestamp = null): SignedPayload
    {
        $ts = $timestamp ?? (int) (microtime(true) * 1000);
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        $message = "{$ts}.{$payload}";
        $signature = self::generateHMACSHA256($message, $apiKey);

        return new SignedPayload(
            data: $data,
            signature: $signature,
            timestamp: $ts,
            keyId: self::getKeyId($apiKey)
        );
    }

    /**
     * Create signature for request headers
     *
     * @return array{signature: string, timestamp: int}
     */
    public static function createRequestSignature(string $body, string $apiKey, ?int $timestamp = null): array
    {
        $ts = $timestamp ?? (int) (microtime(true) * 1000);
        $message = "{$ts}.{$body}";
        $signature = self::generateHMACSHA256($message, $apiKey);

        return [
            'signature' => $signature,
            'timestamp' => $ts,
        ];
    }

    /**
     * Verify a signed payload
     *
     * @param SignedPayload<mixed> $signedPayload
     * @param int $maxAgeMs Maximum age in milliseconds (default: 5 minutes)
     */
    public static function verifySignedPayload(
        SignedPayload $signedPayload,
        string $apiKey,
        int $maxAgeMs = 300000
    ): bool {
        // Check timestamp age
        $now = (int) (microtime(true) * 1000);
        $age = $now - $signedPayload->timestamp;
        if ($age > $maxAgeMs || $age < 0) {
            return false;
        }

        // Verify key ID matches
        if ($signedPayload->keyId !== self::getKeyId($apiKey)) {
            return false;
        }

        // Verify signature
        $payload = json_encode($signedPayload->data, JSON_THROW_ON_ERROR);
        $message = "{$signedPayload->timestamp}.{$payload}";
        $expectedSignature = self::generateHMACSHA256($message, $apiKey);

        return hash_equals($expectedSignature, $signedPayload->signature);
    }
}

/**
 * Encrypted storage for cache data using AES-256-GCM
 */
class EncryptedStorage
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_ITERATIONS = 10000;
    private const KEY_LENGTH = 32;
    private const SALT_LENGTH = 16;
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    private string $encryptionKey;

    /**
     * @param string $apiKey API key used to derive encryption key
     * @param string|null $salt Optional salt for key derivation (random if not provided)
     */
    public function __construct(string $apiKey, ?string $salt = null)
    {
        $this->encryptionKey = $this->deriveKey($apiKey, $salt);
    }

    /**
     * Derive encryption key from API key using PBKDF2
     */
    private function deriveKey(string $apiKey, ?string $salt = null): string
    {
        $salt = $salt ?? random_bytes(self::SALT_LENGTH);

        return hash_pbkdf2(
            'sha256',
            $apiKey,
            $salt,
            self::KEY_ITERATIONS,
            self::KEY_LENGTH,
            true
        );
    }

    /**
     * Encrypt data using AES-256-GCM
     *
     * @param mixed $data Data to encrypt
     * @return string Base64-encoded encrypted data with IV and tag
     * @throws SecurityException If encryption fails
     */
    public function encrypt(mixed $data): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $json,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw SecurityException::encryptionFailed('openssl_encrypt failed');
        }

        // Combine IV + tag + ciphertext
        $combined = $iv . $tag . $ciphertext;

        return base64_encode($combined);
    }

    /**
     * Decrypt data encrypted with AES-256-GCM
     *
     * @param string $encryptedData Base64-encoded encrypted data
     * @return mixed Decrypted data
     * @throws SecurityException If decryption fails
     */
    public function decrypt(string $encryptedData): mixed
    {
        $combined = base64_decode($encryptedData, true);
        if ($combined === false) {
            throw SecurityException::decryptionFailed('Invalid base64 encoding');
        }

        $minLength = self::IV_LENGTH + self::TAG_LENGTH + 1;
        if (strlen($combined) < $minLength) {
            throw SecurityException::decryptionFailed('Invalid encrypted data length');
        }

        // Extract IV, tag, and ciphertext
        $iv = substr($combined, 0, self::IV_LENGTH);
        $tag = substr($combined, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($combined, self::IV_LENGTH + self::TAG_LENGTH);

        $json = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($json === false) {
            throw SecurityException::decryptionFailed('openssl_decrypt failed - possible tampering or wrong key');
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Create a new EncryptedStorage with a random salt
     *
     * @return array{storage: self, salt: string}
     */
    public static function createWithRandomSalt(string $apiKey): array
    {
        $salt = random_bytes(self::SALT_LENGTH);
        $storage = new self($apiKey, $salt);

        return [
            'storage' => $storage,
            'salt' => base64_encode($salt),
        ];
    }

    /**
     * Create an EncryptedStorage from saved salt
     */
    public static function fromSalt(string $apiKey, string $base64Salt): self
    {
        $salt = base64_decode($base64Salt, true);
        if ($salt === false) {
            throw SecurityException::decryptionFailed('Invalid salt encoding');
        }

        return new self($apiKey, $salt);
    }
}
