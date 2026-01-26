<?php

declare(strict_types=1);

namespace FlagKit;

use FlagKit\Error\ErrorCode;
use FlagKit\Error\FlagKitException;
use FlagKit\Error\SecurityException;
use FlagKit\Utils\Security;

class FlagKitOptions
{
    public const DEFAULT_POLLING_INTERVAL = 30;
    public const DEFAULT_CACHE_TTL = 300;
    public const DEFAULT_MAX_CACHE_SIZE = 1000;
    public const DEFAULT_EVENT_BATCH_SIZE = 10;
    public const DEFAULT_EVENT_FLUSH_INTERVAL = 30;
    public const DEFAULT_TIMEOUT = 10;
    public const DEFAULT_RETRY_ATTEMPTS = 3;
    public const DEFAULT_CIRCUIT_BREAKER_THRESHOLD = 5;
    public const DEFAULT_CIRCUIT_BREAKER_RESET_TIMEOUT = 30;
    public const DEFAULT_KEY_ROTATION_GRACE_PERIOD = 300;
    public const DEFAULT_MAX_PERSISTED_EVENTS = 10000;
    public const DEFAULT_PERSISTENCE_FLUSH_INTERVAL = 1000;
    public const DEFAULT_EVALUATION_JITTER_MIN_MS = 5;
    public const DEFAULT_EVALUATION_JITTER_MAX_MS = 15;
    public const DEFAULT_BOOTSTRAP_VERIFICATION_MAX_AGE = 86400000; // 24 hours in ms

    public function __construct(
        public readonly string $apiKey,
        public readonly int $pollingInterval = self::DEFAULT_POLLING_INTERVAL,
        public readonly int $cacheTtl = self::DEFAULT_CACHE_TTL,
        public readonly int $maxCacheSize = self::DEFAULT_MAX_CACHE_SIZE,
        public readonly bool $cacheEnabled = true,
        public readonly int $eventBatchSize = self::DEFAULT_EVENT_BATCH_SIZE,
        public readonly int $eventFlushInterval = self::DEFAULT_EVENT_FLUSH_INTERVAL,
        public readonly bool $eventsEnabled = true,
        public readonly int $timeout = self::DEFAULT_TIMEOUT,
        public readonly int $retryAttempts = self::DEFAULT_RETRY_ATTEMPTS,
        public readonly int $circuitBreakerThreshold = self::DEFAULT_CIRCUIT_BREAKER_THRESHOLD,
        public readonly int $circuitBreakerResetTimeout = self::DEFAULT_CIRCUIT_BREAKER_RESET_TIMEOUT,
        /** @var array<string, mixed>|null */
        public readonly ?array $bootstrap = null,
        /** Local development server port. When set, uses http://localhost:{port}/api/v1 */
        public readonly ?int $localPort = null,
        /** Secondary API key for key rotation support. On 401 errors, SDK will automatically retry with this key. */
        public readonly ?string $secondaryApiKey = null,
        /** Grace period in seconds during key rotation. Default: 300 (5 minutes) */
        public readonly int $keyRotationGracePeriod = self::DEFAULT_KEY_ROTATION_GRACE_PERIOD,
        /** Strict PII mode - throws SecurityException instead of warning when PII detected without privateAttributes */
        public readonly bool $strictPIIMode = false,
        /** Enable request signing for POST requests. Default: true */
        public readonly bool $enableRequestSigning = true,
        /** Enable cache encryption. Default: false */
        public readonly bool $enableCacheEncryption = false,
        /** Enable crash-resilient event persistence. Default: false */
        public readonly bool $persistEvents = false,
        /** Directory path for event storage. Uses system temp dir if null */
        public readonly ?string $eventStoragePath = null,
        /** Maximum number of events to persist. Default: 10000 */
        public readonly int $maxPersistedEvents = self::DEFAULT_MAX_PERSISTED_EVENTS,
        /** Flush interval for event persistence in milliseconds. Default: 1000 */
        public readonly int $persistenceFlushInterval = self::DEFAULT_PERSISTENCE_FLUSH_INTERVAL,
        /** Enable evaluation jitter to protect against cache timing attacks. Default: false */
        public readonly bool $evaluationJitterEnabled = false,
        /** Minimum jitter delay in milliseconds. Default: 5 */
        public readonly int $evaluationJitterMinMs = self::DEFAULT_EVALUATION_JITTER_MIN_MS,
        /** Maximum jitter delay in milliseconds. Default: 15 */
        public readonly int $evaluationJitterMaxMs = self::DEFAULT_EVALUATION_JITTER_MAX_MS,
        /** Enable HMAC-SHA256 signature verification for bootstrap values. Default: true */
        public readonly bool $bootstrapVerificationEnabled = true,
        /** Maximum age in milliseconds for bootstrap timestamp. Default: 86400000 (24 hours) */
        public readonly int $bootstrapVerificationMaxAge = self::DEFAULT_BOOTSTRAP_VERIFICATION_MAX_AGE,
        /** Behavior on verification failure: 'warn' (log and continue), 'error' (throw), 'ignore' (skip verification). Default: 'warn' */
        public readonly string $bootstrapVerificationOnFailure = 'warn'
    ) {
    }

    public function validate(): void
    {
        if (empty($this->apiKey)) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidApiKey,
                'API key is required'
            );
        }

        $validPrefixes = ['sdk_', 'srv_', 'cli_'];
        $hasValidPrefix = false;
        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($this->apiKey, $prefix)) {
                $hasValidPrefix = true;
                break;
            }
        }

        if (!$hasValidPrefix) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidApiKey,
                'Invalid API key format'
            );
        }

        // Validate secondary API key format if provided
        if ($this->secondaryApiKey !== null) {
            $hasValidSecondaryPrefix = false;
            foreach ($validPrefixes as $prefix) {
                if (str_starts_with($this->secondaryApiKey, $prefix)) {
                    $hasValidSecondaryPrefix = true;
                    break;
                }
            }

            if (!$hasValidSecondaryPrefix) {
                throw FlagKitException::configError(
                    ErrorCode::ConfigInvalidApiKey,
                    'Invalid secondary API key format'
                );
            }
        }

        // CRITICAL: Prevent localPort from being used in production
        if ($this->localPort !== null && Security::isProductionEnvironment()) {
            throw SecurityException::localPortInProduction();
        }

        if ($this->pollingInterval <= 0) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidPollingInterval,
                'Polling interval must be positive'
            );
        }

        if ($this->cacheTtl <= 0) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidCacheTtl,
                'Cache TTL must be positive'
            );
        }

        if ($this->keyRotationGracePeriod < 0) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidInterval,
                'Key rotation grace period must be non-negative'
            );
        }

        if ($this->maxPersistedEvents <= 0) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidInterval,
                'Max persisted events must be positive'
            );
        }

        if ($this->persistenceFlushInterval <= 0) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidInterval,
                'Persistence flush interval must be positive'
            );
        }

        if ($this->evaluationJitterMinMs < 0) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidInterval,
                'Evaluation jitter min must be non-negative'
            );
        }

        if ($this->evaluationJitterMaxMs < $this->evaluationJitterMinMs) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidInterval,
                'Evaluation jitter max must be greater than or equal to min'
            );
        }

        if ($this->bootstrapVerificationMaxAge <= 0) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidInterval,
                'Bootstrap verification max age must be positive'
            );
        }

        $validOnFailure = ['warn', 'error', 'ignore'];
        if (!in_array($this->bootstrapVerificationOnFailure, $validOnFailure, true)) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidInterval,
                "Bootstrap verification on failure must be one of: " . implode(', ', $validOnFailure)
            );
        }
    }

    public static function builder(string $apiKey): FlagKitOptionsBuilder
    {
        return new FlagKitOptionsBuilder($apiKey);
    }
}

class FlagKitOptionsBuilder
{
    private int $pollingInterval = FlagKitOptions::DEFAULT_POLLING_INTERVAL;
    private int $cacheTtl = FlagKitOptions::DEFAULT_CACHE_TTL;
    private int $maxCacheSize = FlagKitOptions::DEFAULT_MAX_CACHE_SIZE;
    private bool $cacheEnabled = true;
    private int $eventBatchSize = FlagKitOptions::DEFAULT_EVENT_BATCH_SIZE;
    private int $eventFlushInterval = FlagKitOptions::DEFAULT_EVENT_FLUSH_INTERVAL;
    private bool $eventsEnabled = true;
    private int $timeout = FlagKitOptions::DEFAULT_TIMEOUT;
    private int $retryAttempts = FlagKitOptions::DEFAULT_RETRY_ATTEMPTS;
    private int $circuitBreakerThreshold = FlagKitOptions::DEFAULT_CIRCUIT_BREAKER_THRESHOLD;
    private int $circuitBreakerResetTimeout = FlagKitOptions::DEFAULT_CIRCUIT_BREAKER_RESET_TIMEOUT;
    /** @var array<string, mixed>|null */
    private ?array $bootstrap = null;
    private ?int $localPort = null;
    private ?string $secondaryApiKey = null;
    private int $keyRotationGracePeriod = FlagKitOptions::DEFAULT_KEY_ROTATION_GRACE_PERIOD;
    private bool $strictPIIMode = false;
    private bool $enableRequestSigning = true;
    private bool $enableCacheEncryption = false;
    private bool $persistEvents = false;
    private ?string $eventStoragePath = null;
    private int $maxPersistedEvents = FlagKitOptions::DEFAULT_MAX_PERSISTED_EVENTS;
    private int $persistenceFlushInterval = FlagKitOptions::DEFAULT_PERSISTENCE_FLUSH_INTERVAL;
    private bool $evaluationJitterEnabled = false;
    private int $evaluationJitterMinMs = FlagKitOptions::DEFAULT_EVALUATION_JITTER_MIN_MS;
    private int $evaluationJitterMaxMs = FlagKitOptions::DEFAULT_EVALUATION_JITTER_MAX_MS;
    private bool $bootstrapVerificationEnabled = true;
    private int $bootstrapVerificationMaxAge = FlagKitOptions::DEFAULT_BOOTSTRAP_VERIFICATION_MAX_AGE;
    private string $bootstrapVerificationOnFailure = 'warn';

    public function __construct(
        private readonly string $apiKey
    ) {
    }

    public function pollingInterval(int $seconds): self
    {
        $this->pollingInterval = $seconds;
        return $this;
    }

    public function cacheTtl(int $seconds): self
    {
        $this->cacheTtl = $seconds;
        return $this;
    }

    public function maxCacheSize(int $size): self
    {
        $this->maxCacheSize = $size;
        return $this;
    }

    public function cacheEnabled(bool $enabled): self
    {
        $this->cacheEnabled = $enabled;
        return $this;
    }

    public function eventBatchSize(int $size): self
    {
        $this->eventBatchSize = $size;
        return $this;
    }

    public function eventFlushInterval(int $seconds): self
    {
        $this->eventFlushInterval = $seconds;
        return $this;
    }

    public function eventsEnabled(bool $enabled): self
    {
        $this->eventsEnabled = $enabled;
        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;
        return $this;
    }

    public function retryAttempts(int $attempts): self
    {
        $this->retryAttempts = $attempts;
        return $this;
    }

    public function circuitBreakerThreshold(int $threshold): self
    {
        $this->circuitBreakerThreshold = $threshold;
        return $this;
    }

    public function circuitBreakerResetTimeout(int $seconds): self
    {
        $this->circuitBreakerResetTimeout = $seconds;
        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function bootstrap(array $data): self
    {
        $this->bootstrap = $data;
        return $this;
    }

    public function localPort(int $port): self
    {
        $this->localPort = $port;
        return $this;
    }

    public function secondaryApiKey(string $key): self
    {
        $this->secondaryApiKey = $key;
        return $this;
    }

    public function keyRotationGracePeriod(int $seconds): self
    {
        $this->keyRotationGracePeriod = $seconds;
        return $this;
    }

    public function strictPIIMode(bool $enabled): self
    {
        $this->strictPIIMode = $enabled;
        return $this;
    }

    public function enableRequestSigning(bool $enabled): self
    {
        $this->enableRequestSigning = $enabled;
        return $this;
    }

    public function enableCacheEncryption(bool $enabled): self
    {
        $this->enableCacheEncryption = $enabled;
        return $this;
    }

    public function persistEvents(bool $enabled): self
    {
        $this->persistEvents = $enabled;
        return $this;
    }

    public function eventStoragePath(string $path): self
    {
        $this->eventStoragePath = $path;
        return $this;
    }

    public function maxPersistedEvents(int $max): self
    {
        $this->maxPersistedEvents = $max;
        return $this;
    }

    public function persistenceFlushInterval(int $milliseconds): self
    {
        $this->persistenceFlushInterval = $milliseconds;
        return $this;
    }

    public function evaluationJitterEnabled(bool $enabled): self
    {
        $this->evaluationJitterEnabled = $enabled;
        return $this;
    }

    public function evaluationJitterMinMs(int $milliseconds): self
    {
        $this->evaluationJitterMinMs = $milliseconds;
        return $this;
    }

    public function evaluationJitterMaxMs(int $milliseconds): self
    {
        $this->evaluationJitterMaxMs = $milliseconds;
        return $this;
    }

    public function bootstrapVerificationEnabled(bool $enabled): self
    {
        $this->bootstrapVerificationEnabled = $enabled;
        return $this;
    }

    public function bootstrapVerificationMaxAge(int $milliseconds): self
    {
        $this->bootstrapVerificationMaxAge = $milliseconds;
        return $this;
    }

    public function bootstrapVerificationOnFailure(string $behavior): self
    {
        $this->bootstrapVerificationOnFailure = $behavior;
        return $this;
    }

    public function build(): FlagKitOptions
    {
        return new FlagKitOptions(
            apiKey: $this->apiKey,
            pollingInterval: $this->pollingInterval,
            cacheTtl: $this->cacheTtl,
            maxCacheSize: $this->maxCacheSize,
            cacheEnabled: $this->cacheEnabled,
            eventBatchSize: $this->eventBatchSize,
            eventFlushInterval: $this->eventFlushInterval,
            eventsEnabled: $this->eventsEnabled,
            timeout: $this->timeout,
            retryAttempts: $this->retryAttempts,
            circuitBreakerThreshold: $this->circuitBreakerThreshold,
            circuitBreakerResetTimeout: $this->circuitBreakerResetTimeout,
            bootstrap: $this->bootstrap,
            localPort: $this->localPort,
            secondaryApiKey: $this->secondaryApiKey,
            keyRotationGracePeriod: $this->keyRotationGracePeriod,
            strictPIIMode: $this->strictPIIMode,
            enableRequestSigning: $this->enableRequestSigning,
            enableCacheEncryption: $this->enableCacheEncryption,
            persistEvents: $this->persistEvents,
            eventStoragePath: $this->eventStoragePath,
            maxPersistedEvents: $this->maxPersistedEvents,
            persistenceFlushInterval: $this->persistenceFlushInterval,
            evaluationJitterEnabled: $this->evaluationJitterEnabled,
            evaluationJitterMinMs: $this->evaluationJitterMinMs,
            evaluationJitterMaxMs: $this->evaluationJitterMaxMs,
            bootstrapVerificationEnabled: $this->bootstrapVerificationEnabled,
            bootstrapVerificationMaxAge: $this->bootstrapVerificationMaxAge,
            bootstrapVerificationOnFailure: $this->bootstrapVerificationOnFailure
        );
    }
}
