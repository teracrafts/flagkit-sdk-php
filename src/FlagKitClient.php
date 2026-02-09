<?php

declare(strict_types=1);

namespace FlagKit;

use FlagKit\Core\AnalyticsEvent;
use FlagKit\Core\ContextManager;
use FlagKit\Core\EventQueue;
use FlagKit\Core\FlagCache;
use FlagKit\Core\PollingConfig;
use FlagKit\Core\PollingManager;
use FlagKit\Error\ErrorCode;
use FlagKit\Error\FlagKitException;
use FlagKit\Error\SecurityException;
use FlagKit\Http\HttpClient;
use FlagKit\Types\EvaluationContext;
use FlagKit\Types\EvaluationReason;
use FlagKit\Types\EvaluationResult;
use FlagKit\Types\FlagState;
use FlagKit\Types\FlagValue;
use FlagKit\Utils\Security;
use FlagKit\Utils\VersionUtils;
use Psr\Log\LoggerInterface;

/**
 * FlagKit client for feature flag evaluation.
 *
 * This is the main entry point for the FlagKit SDK. It provides methods for:
 * - Initializing the SDK and loading flags
 * - Evaluating feature flags with user context
 * - Managing user identity and context
 * - Tracking custom analytics events
 * - Background polling for flag updates
 */
class FlagKitClient
{
    public const SDK_VERSION = '1.0.4';
    public const SDK_LANGUAGE = 'php';

    private HttpClient $httpClient;
    private FlagCache $cache;
    private ContextManager $contextManager;
    private ?EventQueue $eventQueue = null;
    private ?PollingManager $pollingManager = null;
    private ?LoggerInterface $logger = null;

    private bool $initialized = false;
    private bool $ready = false;
    private ?string $environmentId = null;
    private ?string $sessionId = null;
    private ?string $lastPolledAt = null;

    /** @var callable|null */
    private $onReady = null;
    /** @var callable|null */
    private $onError = null;
    /** @var callable|null */
    private $onUpdate = null;

    public function __construct(
        private readonly FlagKitOptions $options
    ) {
        $options->validate();

        $this->logger = $options->logger;
        $this->httpClient = new HttpClient($options, $this->logger);
        $this->cache = new FlagCache($options->maxCacheSize, $options->cacheTtl);
        $this->contextManager = new ContextManager();
        $this->sessionId = $this->generateSessionId();

        if ($options->eventsEnabled) {
            $this->eventQueue = new EventQueue(
                $options->eventBatchSize,
                $options->eventFlushInterval
            );
            $this->eventQueue->setOnFlush(fn(array $events) => $this->sendEvents($events));
            $this->eventQueue->setSessionId($this->sessionId);
            $this->eventQueue->setSdkVersion(self::SDK_VERSION);
        }

        if ($options->bootstrap !== null) {
            $this->loadBootstrap($options->bootstrap);
        }

        // Set up polling manager if polling is enabled
        $this->setupPollingManager();
    }

    /**
     * Check if SDK is initialized.
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * Check if SDK is ready (initialized and has flags).
     */
    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Initialize the SDK by fetching initial flag configuration.
     *
     * @throws FlagKitException If initialization fails
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            $response = $this->httpClient->get('/sdk/init');

            if (isset($response['flags']) && is_array($response['flags'])) {
                foreach ($response['flags'] as $flagData) {
                    $flag = FlagState::fromArray($flagData);
                    $this->cache->set($flag->key, $flag);
                }
            }

            // Store environment metadata
            if (isset($response['environmentId'])) {
                $this->environmentId = $response['environmentId'];
                $this->eventQueue?->setEnvironmentId($this->environmentId);
            }

            // Update polling interval if provided by server
            if (isset($response['pollingIntervalSeconds']) && $this->pollingManager !== null) {
                $this->pollingManager->setConfig(
                    (new PollingConfig())->withInterval((int) $response['pollingIntervalSeconds'])
                );
            }

            // Check SDK version metadata and emit warnings
            $this->checkVersionMetadata($response);

            $this->initialized = true;
            $this->ready = true;
            $this->lastPolledAt = date('c');

            // Start polling if enabled
            $this->pollingManager?->start();

            // Trigger onReady callback
            if ($this->onReady !== null) {
                ($this->onReady)();
            }
        } catch (\Throwable $e) {
            // Trigger onError callback
            if ($this->onError !== null) {
                ($this->onError)($e);
            }

            // If we have bootstrap values, consider SDK as partially ready
            if (!$this->cache->isEmpty()) {
                $this->initialized = true;
                $this->ready = true;
            }

            throw FlagKitException::initError('Failed to initialize SDK: ' . $e->getMessage());
        }
    }

    /**
     * Wait for the SDK to be ready.
     * In PHP (synchronous), this just ensures initialization is complete.
     *
     * @param int $timeoutSeconds Maximum time to wait
     * @throws FlagKitException If timeout exceeded or initialization fails
     */
    public function waitForReady(int $timeoutSeconds = 10): void
    {
        if ($this->ready) {
            return;
        }

        if (!$this->initialized) {
            $this->initialize();
        }
    }

    /**
     * Set the onReady callback.
     */
    public function onReady(callable $callback): self
    {
        $this->onReady = $callback;
        if ($this->ready) {
            $callback();
        }
        return $this;
    }

    /**
     * Set the onError callback.
     */
    public function onError(callable $callback): self
    {
        $this->onError = $callback;
        return $this;
    }

    /**
     * Set the onUpdate callback (called when flags are updated).
     *
     * @param callable(FlagState[]): void $callback
     */
    public function onUpdate(callable $callback): self
    {
        $this->onUpdate = $callback;
        return $this;
    }

    /**
     * Identify a user.
     *
     * @param array<string, mixed>|null $attributes Additional user attributes
     * @throws SecurityException If strictPIIMode is enabled and PII is detected without privateAttributes
     */
    public function identify(string $userId, ?array $attributes = null): void
    {
        // Security: check for potential PII in attributes
        if ($attributes !== null && !isset($attributes['privateAttributes'])) {
            $this->enforcePIIPolicy($attributes, 'identify attributes');
        }

        $this->contextManager->identify($userId, $attributes);
        $this->eventQueue?->trackIdentify($userId, $attributes);
    }

    /**
     * Set the global evaluation context.
     *
     * @throws SecurityException If strictPIIMode is enabled and PII is detected without privateAttributes
     */
    public function setContext(EvaluationContext $context): void
    {
        // Security: check for potential PII in context attributes
        $contextArray = $context->toArray();
        $hasPrivateAttributes = isset($contextArray['attributes']['privateAttributes'])
            || $this->hasPrivateAttributePrefix($context);

        if (!$hasPrivateAttributes && isset($contextArray['attributes'])) {
            $this->enforcePIIPolicy($contextArray['attributes'], 'context');
        }

        $this->contextManager->setContext($context);
    }

    /**
     * Check if context has any attributes with private prefix (_).
     */
    private function hasPrivateAttributePrefix(EvaluationContext $context): bool
    {
        foreach (array_keys($context->toArray()['attributes'] ?? []) as $key) {
            if (str_starts_with($key, '_')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the current global context.
     */
    public function getContext(): ?EvaluationContext
    {
        return $this->contextManager->getContext();
    }

    /**
     * Clear the global context.
     */
    public function clearContext(): void
    {
        $this->contextManager->clearContext();
    }

    /**
     * Reset to anonymous state.
     */
    public function reset(): void
    {
        $this->contextManager->reset();
    }

    /**
     * Get the global context (legacy method).
     * @deprecated Use getContext() instead
     */
    public function getGlobalContext(): ?EvaluationContext
    {
        return $this->getContext();
    }

    /**
     * Evaluate a feature flag.
     */
    public function evaluate(string $flagKey, ?EvaluationContext $context = null): EvaluationResult
    {
        $this->applyEvaluationJitter();
        $mergedContext = $this->contextManager->getMergedContext($context);
        $flag = $this->cache->get($flagKey);

        // Try cache first
        if ($flag !== null) {
            $result = new EvaluationResult(
                flagKey: $flagKey,
                value: $flag->value,
                enabled: $flag->enabled,
                reason: EvaluationReason::Cached,
                version: $flag->version
            );

            $this->trackEvaluation($flagKey, $flag->value->getRaw(), $mergedContext);
            return $result;
        }

        // Try stale cache for fallback
        $staleFlag = $this->cache->getStaleFlag($flagKey);
        if ($staleFlag !== null) {
            $result = new EvaluationResult(
                flagKey: $flagKey,
                value: $staleFlag->value,
                enabled: $staleFlag->enabled,
                reason: EvaluationReason::Stale,
                version: $staleFlag->version
            );

            $this->trackEvaluation($flagKey, $staleFlag->value->getRaw(), $mergedContext);
            return $result;
        }

        // Flag not found
        return EvaluationResult::defaultResult(
            $flagKey,
            FlagValue::from(null),
            EvaluationReason::FlagNotFound
        );
    }

    /**
     * Evaluate a flag with server-side evaluation (async in spirit).
     */
    public function evaluateAsync(string $flagKey, ?EvaluationContext $context = null): EvaluationResult
    {
        $mergedContext = $this->contextManager->getMergedContext($context);
        $resolvedContext = $mergedContext?->stripPrivateAttributes();

        try {
            $response = $this->httpClient->post('/sdk/evaluate', [
                'flagKey' => $flagKey,
                'context' => $resolvedContext?->toArray() ?? [],
            ]);

            $result = new EvaluationResult(
                flagKey: $response['flagKey'],
                value: FlagValue::from($response['value']),
                enabled: $response['enabled'] ?? false,
                reason: EvaluationReason::fromString($response['reason'] ?? 'server'),
                version: $response['version'] ?? 0
            );

            $this->trackEvaluation($flagKey, $response['value'], $mergedContext);
            return $result;
        } catch (\Throwable $e) {
            // Trigger error callback
            if ($this->onError !== null) {
                ($this->onError)($e);
            }

            // Fall back to cache
            return $this->evaluate($flagKey, $context);
        }
    }

    /**
     * Evaluate all flags with context.
     *
     * @return array<string, EvaluationResult>
     */
    public function evaluateAll(?EvaluationContext $context = null): array
    {
        $mergedContext = $this->contextManager->getMergedContext($context);
        $resolvedContext = $mergedContext?->stripPrivateAttributes();

        try {
            $response = $this->httpClient->post('/sdk/evaluate/all', [
                'context' => $resolvedContext?->toArray() ?? [],
            ]);

            $results = [];
            if (isset($response['flags']) && is_array($response['flags'])) {
                foreach ($response['flags'] as $key => $flagData) {
                    $results[$key] = new EvaluationResult(
                        flagKey: $flagData['flagKey'] ?? $key,
                        value: FlagValue::from($flagData['value']),
                        enabled: $flagData['enabled'] ?? false,
                        reason: EvaluationReason::fromString($flagData['reason'] ?? 'server'),
                        version: $flagData['version'] ?? 0
                    );
                }
            }

            return $results;
        } catch (\Throwable $e) {
            // Trigger error callback
            if ($this->onError !== null) {
                ($this->onError)($e);
            }

            // Fall back to cached flags
            $results = [];
            foreach ($this->cache->getAllFlags() as $key => $flag) {
                $results[$key] = new EvaluationResult(
                    flagKey: $key,
                    value: $flag->value,
                    enabled: $flag->enabled,
                    reason: EvaluationReason::Cached,
                    version: $flag->version
                );
            }

            return $results;
        }
    }

    /**
     * Get a boolean flag value.
     */
    public function getBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): bool
    {
        $result = $this->evaluate($flagKey, $context);
        if ($result->reason === EvaluationReason::FlagNotFound) {
            return $defaultValue;
        }
        return $result->getBoolValue();
    }

    /**
     * Get a string flag value.
     */
    public function getStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): string
    {
        $result = $this->evaluate($flagKey, $context);
        if ($result->reason === EvaluationReason::FlagNotFound) {
            return $defaultValue;
        }
        return $result->getStringValue() ?? $defaultValue;
    }

    /**
     * Get a number flag value.
     */
    public function getNumberValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): float
    {
        $result = $this->evaluate($flagKey, $context);
        if ($result->reason === EvaluationReason::FlagNotFound) {
            return $defaultValue;
        }
        return $result->getNumberValue();
    }

    /**
     * Get an integer flag value.
     */
    public function getIntValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): int
    {
        $result = $this->evaluate($flagKey, $context);
        if ($result->reason === EvaluationReason::FlagNotFound) {
            return $defaultValue;
        }
        return $result->getIntValue();
    }

    /**
     * Get a JSON flag value.
     *
     * @param array<string, mixed>|null $defaultValue
     * @return array<string, mixed>|null
     */
    public function getJsonValue(string $flagKey, ?array $defaultValue, ?EvaluationContext $context = null): ?array
    {
        $result = $this->evaluate($flagKey, $context);
        if ($result->reason === EvaluationReason::FlagNotFound) {
            return $defaultValue;
        }
        return $result->getJsonValue() ?? $defaultValue;
    }

    /**
     * Check if a flag exists.
     */
    public function hasFlag(string $flagKey): bool
    {
        return $this->cache->hasFlag($flagKey);
    }

    /**
     * Get all flag keys.
     *
     * @return string[]
     */
    public function getAllFlagKeys(): array
    {
        return $this->cache->getAllFlagKeys();
    }

    /**
     * Get all cached flags.
     *
     * @return array<string, FlagState>
     */
    public function getAllFlags(): array
    {
        return $this->cache->getAllFlags();
    }

    /**
     * Force refresh flags from server.
     */
    public function refresh(): void
    {
        try {
            $response = $this->httpClient->get('/sdk/init');

            $updatedFlags = [];
            if (isset($response['flags']) && is_array($response['flags'])) {
                foreach ($response['flags'] as $flagData) {
                    $flag = FlagState::fromArray($flagData);
                    $this->cache->set($flag->key, $flag);
                    $updatedFlags[] = $flag;
                }
            }

            $this->lastPolledAt = date('c');

            // Trigger onUpdate callback if flags were updated
            if (!empty($updatedFlags) && $this->onUpdate !== null) {
                ($this->onUpdate)($updatedFlags);
            }
        } catch (\Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }
            throw $e;
        }
    }

    /**
     * Track a custom event.
     *
     * @param array<string, mixed>|null $data Event data
     * @throws SecurityException If strictPIIMode is enabled and PII is detected in event data
     */
    public function track(string $eventType, ?array $data = null): void
    {
        // Security: check for potential PII in event data
        if ($data !== null) {
            $this->enforcePIIPolicy($data, 'event');
        }

        $this->eventQueue?->trackCustom($eventType, $data);
    }

    /**
     * Flush pending events immediately.
     */
    public function flush(): void
    {
        $this->eventQueue?->flushAll();
    }

    /**
     * Poll for flag updates.
     */
    public function pollForUpdates(?string $since = null): void
    {
        $sinceParam = $since ?? $this->lastPolledAt;
        $path = $sinceParam !== null
            ? "/sdk/updates?since=" . urlencode($sinceParam)
            : '/sdk/updates';

        try {
            $response = $this->httpClient->get($path);

            $updatedFlags = [];
            if (isset($response['flags']) && is_array($response['flags'])) {
                foreach ($response['flags'] as $flagData) {
                    $flag = FlagState::fromArray($flagData);
                    $this->cache->set($flag->key, $flag);
                    $updatedFlags[] = $flag;
                }
            }

            $this->lastPolledAt = $response['checkedAt'] ?? date('c');

            // Trigger onUpdate callback
            if (!empty($updatedFlags) && $this->onUpdate !== null) {
                ($this->onUpdate)($updatedFlags);
            }

            // Record success in polling manager
            $this->pollingManager?->onSuccess();
        } catch (\Throwable $e) {
            // Record error in polling manager
            $this->pollingManager?->onError($e->getMessage());

            if ($this->onError !== null) {
                ($this->onError)($e);
            }
        }
    }

    /**
     * Start background polling.
     */
    public function startPolling(): void
    {
        $this->pollingManager?->start();
    }

    /**
     * Stop background polling.
     */
    public function stopPolling(): void
    {
        $this->pollingManager?->stop();
    }

    /**
     * Check if polling is active.
     */
    public function isPollingActive(): bool
    {
        return $this->pollingManager?->isActive() ?? false;
    }

    /**
     * Close the SDK and cleanup resources.
     */
    public function close(): void
    {
        // Stop polling
        $this->pollingManager?->stop();

        // Flush any pending events
        $this->flush();

        // Stop event queue
        $this->eventQueue?->stop();

        $this->initialized = false;
        $this->ready = false;
    }

    /**
     * Get SDK statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'initialized' => $this->initialized,
            'ready' => $this->ready,
            'environmentId' => $this->environmentId,
            'sessionId' => $this->sessionId,
            'lastPolledAt' => $this->lastPolledAt,
            'cache' => $this->cache->getStats(),
            'eventQueue' => $this->eventQueue?->getStats(),
            'polling' => $this->pollingManager?->getStats(),
            'context' => $this->contextManager->getStats(),
        ];
    }

    /**
     * Get the session ID.
     */
    public function getSessionId(): string
    {
        return $this->sessionId ?? '';
    }

    /**
     * Get the environment ID.
     */
    public function getEnvironmentId(): ?string
    {
        return $this->environmentId;
    }

    // --------------------------------------------------
    // Private helper methods
    // --------------------------------------------------

    /**
     * Apply evaluation jitter to protect against cache timing attacks.
     *
     * When enabled, adds a random delay before flag evaluation to make it
     * harder for attackers to determine cache state based on response timing.
     */
    private function applyEvaluationJitter(): void
    {
        if (!$this->options->evaluationJitterEnabled) {
            return;
        }

        $jitterMs = random_int(
            $this->options->evaluationJitterMinMs,
            $this->options->evaluationJitterMaxMs
        );

        // usleep takes microseconds, so multiply by 1000
        usleep($jitterMs * 1000);
    }

    private function setupPollingManager(): void
    {
        if ($this->options->pollingInterval > 0) {
            $config = (new PollingConfig())->withInterval($this->options->pollingInterval);

            $this->pollingManager = new PollingManager(
                fn() => $this->pollForUpdates(),
                $config
            );
        }
    }

    private function trackEvaluation(string $flagKey, mixed $value, ?EvaluationContext $context): void
    {
        $this->eventQueue?->trackEvaluation(
            $flagKey,
            $value,
            $context?->stripPrivateAttributes()
        );
    }

    /**
     * @param array<string, mixed> $bootstrap
     */
    private function loadBootstrap(array $bootstrap): void
    {
        // Determine if this is the new format (has 'flags' key) or legacy format
        if (isset($bootstrap['flags']) && is_array($bootstrap['flags'])) {
            // New format - verify signature if present
            $this->applyBootstrap($bootstrap);
        } else {
            // Legacy format - load directly
            $this->applyLegacyBootstrap($bootstrap);
        }
    }

    /**
     * Apply bootstrap with optional signature verification.
     *
     * @param array<string, mixed> $bootstrap
     */
    private function applyBootstrap(array $bootstrap): void
    {
        // Verify signature if present
        if (isset($bootstrap['signature'])) {
            $result = Security::verifyBootstrapSignature(
                $bootstrap,
                $this->options->apiKey,
                $this->options->bootstrapVerificationEnabled,
                $this->options->bootstrapVerificationMaxAge,
                $this->options->bootstrapVerificationOnFailure
            );

            if (!$result['valid']) {
                $this->handleBootstrapVerificationFailure($result['error']);
                return;
            }
        }

        // Load flags from the new format
        foreach ($bootstrap['flags'] as $key => $value) {
            $flag = new FlagState(
                key: $key,
                value: FlagValue::from($value),
                enabled: true,
                version: 0
            );
            $this->cache->set($key, $flag);
        }
    }

    /**
     * Apply legacy bootstrap format (direct key-value pairs).
     *
     * @param array<string, mixed> $bootstrap
     */
    private function applyLegacyBootstrap(array $bootstrap): void
    {
        foreach ($bootstrap as $key => $value) {
            $flag = new FlagState(
                key: $key,
                value: FlagValue::from($value),
                enabled: true,
                version: 0
            );
            $this->cache->set($key, $flag);
        }
    }

    /**
     * Handle bootstrap verification failure based on onFailure setting.
     */
    private function handleBootstrapVerificationFailure(?string $error): void
    {
        $message = "[FlagKit Security] Bootstrap verification failed: {$error}";

        switch ($this->options->bootstrapVerificationOnFailure) {
            case 'error':
                throw SecurityException::bootstrapVerificationFailed($error ?? 'Unknown error');

            case 'warn':
                error_log($message);
                // Trigger error callback if set
                if ($this->onError !== null) {
                    ($this->onError)(SecurityException::bootstrapVerificationFailed($error ?? 'Unknown error'));
                }
                break;

            case 'ignore':
            default:
                // Do nothing
                break;
        }
    }

    /**
     * @param AnalyticsEvent[] $events
     */
    private function sendEvents(array $events): void
    {
        try {
            $this->httpClient->post('/sdk/events/batch', [
                'events' => array_map(fn($e) => $e->toArray(), $events),
            ]);
        } catch (\Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }
            throw $e;
        }
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Enforce PII policy based on strictPIIMode option.
     *
     * When strictPIIMode is enabled, throws SecurityException if PII is detected.
     * When strictPIIMode is disabled, logs a warning.
     *
     * @param array<string, mixed> $data Data to check for PII
     * @param string $dataType Type of data for error messages (e.g., 'context', 'event', 'identify attributes')
     * @throws SecurityException If strictPIIMode is enabled and PII is detected
     */
    private function enforcePIIPolicy(array $data, string $dataType): void
    {
        $piiResult = Security::checkForPotentialPII($data, $dataType);

        if ($piiResult->hasPII) {
            if ($this->options->strictPIIMode) {
                throw SecurityException::piiDetected(implode(', ', $piiResult->fields));
            } else {
                // Log warning when not in strict mode
                error_log($piiResult->message);
            }
        }
    }

    /**
     * Check SDK version metadata from init response and emit appropriate warnings.
     *
     * Per spec, the SDK should parse and surface:
     * - sdkVersionMin: Minimum required version (older may not work)
     * - sdkVersionRecommended: Recommended version for optimal experience
     * - sdkVersionLatest: Latest available version
     * - deprecationWarning: Server-provided deprecation message
     *
     * @param array<string, mixed> $response The init response from the server
     */
    private function checkVersionMetadata(array $response): void
    {
        $metadata = $response['metadata'] ?? null;
        if (!is_array($metadata)) {
            return;
        }

        $sdkVersion = self::SDK_VERSION;

        // Check for server-provided deprecation warning first
        if (!empty($metadata['deprecationWarning'])) {
            $this->logWarning("[FlagKit] Deprecation Warning: {$metadata['deprecationWarning']}");
        }

        // Check minimum version requirement
        if (!empty($metadata['sdkVersionMin'])) {
            $minVersion = $metadata['sdkVersionMin'];
            if (VersionUtils::isVersionLessThan($sdkVersion, $minVersion)) {
                $this->logError(
                    "[FlagKit] SDK version {$sdkVersion} is below minimum required version {$minVersion}. " .
                    'Some features may not work correctly. Please upgrade the SDK.'
                );
            }
        }

        // Check recommended version
        $warnedAboutRecommended = false;
        if (!empty($metadata['sdkVersionRecommended'])) {
            $recommendedVersion = $metadata['sdkVersionRecommended'];
            if (VersionUtils::isVersionLessThan($sdkVersion, $recommendedVersion)) {
                $this->logWarning(
                    "[FlagKit] SDK version {$sdkVersion} is below recommended version {$recommendedVersion}. " .
                    'Consider upgrading for the best experience.'
                );
                $warnedAboutRecommended = true;
            }
        }

        // Log if a newer version is available (info level, not a warning)
        // Only log if we haven't already warned about recommended
        if (!empty($metadata['sdkVersionLatest']) && !$warnedAboutRecommended) {
            $latestVersion = $metadata['sdkVersionLatest'];
            if (VersionUtils::isVersionLessThan($sdkVersion, $latestVersion)) {
                $this->logInfo(
                    "[FlagKit] SDK version {$sdkVersion} - a newer version {$latestVersion} is available."
                );
            }
        }
    }

    /**
     * Log an error message using the configured logger or error_log fallback.
     */
    private function logError(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message);
        } else {
            error_log($message);
        }
    }

    /**
     * Log a warning message using the configured logger or error_log fallback.
     */
    private function logWarning(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->warning($message);
        } else {
            error_log($message);
        }
    }

    /**
     * Log an info message using the configured logger or error_log fallback.
     */
    private function logInfo(string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->info($message);
        } else {
            // For info level, we still log it but at a lower priority
            // Some users may not want info messages cluttering their logs
            error_log($message);
        }
    }
}
