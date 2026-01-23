<?php

declare(strict_types=1);

namespace FlagKit;

class FlagKitOptions
{
    public const DEFAULT_BASE_URL = 'https://api.flagkit.dev/api/v1';
    public const DEFAULT_POLLING_INTERVAL = 30;
    public const DEFAULT_CACHE_TTL = 300;
    public const DEFAULT_MAX_CACHE_SIZE = 1000;
    public const DEFAULT_EVENT_BATCH_SIZE = 10;
    public const DEFAULT_EVENT_FLUSH_INTERVAL = 30;
    public const DEFAULT_TIMEOUT = 10;
    public const DEFAULT_RETRY_ATTEMPTS = 3;
    public const DEFAULT_CIRCUIT_BREAKER_THRESHOLD = 5;
    public const DEFAULT_CIRCUIT_BREAKER_RESET_TIMEOUT = 30;

    public function __construct(
        public readonly string $apiKey,
        public readonly string $baseUrl = self::DEFAULT_BASE_URL,
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
        public readonly ?array $bootstrap = null
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

        if (!filter_var($this->baseUrl, FILTER_VALIDATE_URL)) {
            throw FlagKitException::configError(
                ErrorCode::ConfigInvalidBaseUrl,
                'Invalid base URL'
            );
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
    }

    public static function builder(string $apiKey): FlagKitOptionsBuilder
    {
        return new FlagKitOptionsBuilder($apiKey);
    }
}

class FlagKitOptionsBuilder
{
    private string $baseUrl = FlagKitOptions::DEFAULT_BASE_URL;
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

    public function __construct(
        private readonly string $apiKey
    ) {
    }

    public function baseUrl(string $url): self
    {
        $this->baseUrl = $url;
        return $this;
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

    public function build(): FlagKitOptions
    {
        return new FlagKitOptions(
            apiKey: $this->apiKey,
            baseUrl: $this->baseUrl,
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
            bootstrap: $this->bootstrap
        );
    }
}
