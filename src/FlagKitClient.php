<?php

declare(strict_types=1);

namespace FlagKit;

class FlagKitClient
{
    private HttpClient $httpClient;
    private FlagCache $cache;
    private ?EventQueue $eventQueue = null;
    private EvaluationContext $globalContext;
    private bool $initialized = false;

    public function __construct(
        private readonly FlagKitOptions $options
    ) {
        $options->validate();

        $this->httpClient = new HttpClient($options);
        $this->cache = new FlagCache($options->maxCacheSize, $options->cacheTtl);
        $this->globalContext = new EvaluationContext();

        if ($options->eventsEnabled) {
            $this->eventQueue = new EventQueue(
                $options->eventBatchSize,
                $options->eventFlushInterval
            );
            $this->eventQueue->setOnFlush(fn(array $events) => $this->sendEvents($events));
        }

        if ($options->bootstrap !== null) {
            $this->loadBootstrap($options->bootstrap);
        }
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    public function initialize(): void
    {
        $response = $this->httpClient->get('/sdk/init');

        if (isset($response['flags']) && is_array($response['flags'])) {
            foreach ($response['flags'] as $flagData) {
                $flag = FlagState::fromArray($flagData);
                $this->cache->set($flag->key, $flag);
            }
        }

        $this->initialized = true;
    }

    /**
     * @param array<string, mixed>|null $attributes
     */
    public function identify(string $userId, ?array $attributes = null): void
    {
        $this->globalContext = $this->globalContext->withUserId($userId);

        if ($attributes !== null) {
            $this->globalContext = $this->globalContext->withAttributes($attributes);
        }

        $this->eventQueue?->trackIdentify($userId, $attributes);
    }

    public function setContext(EvaluationContext $context): void
    {
        $this->globalContext = $context;
    }

    public function clearContext(): void
    {
        $this->globalContext = new EvaluationContext();
    }

    public function getGlobalContext(): EvaluationContext
    {
        return $this->globalContext;
    }

    public function evaluate(string $flagKey, ?EvaluationContext $context = null): EvaluationResult
    {
        $mergedContext = $this->mergeContext($context);
        $flag = $this->cache->get($flagKey);

        if ($flag === null) {
            return EvaluationResult::defaultResult(
                $flagKey,
                FlagValue::from(null),
                EvaluationReason::FlagNotFound
            );
        }

        $result = new EvaluationResult(
            flagKey: $flagKey,
            value: $flag->value,
            enabled: $flag->enabled,
            reason: EvaluationReason::Cached,
            version: $flag->version
        );

        $this->eventQueue?->trackEvaluation(
            $flagKey,
            $flag->value->getRaw(),
            $mergedContext->stripPrivateAttributes()
        );

        return $result;
    }

    public function evaluateAsync(string $flagKey, ?EvaluationContext $context = null): EvaluationResult
    {
        $mergedContext = $this->mergeContext($context);

        try {
            $response = $this->httpClient->post('/sdk/evaluate', [
                'flagKey' => $flagKey,
                'context' => $mergedContext->stripPrivateAttributes()->toArray(),
            ]);

            $result = new EvaluationResult(
                flagKey: $response['flagKey'],
                value: FlagValue::from($response['value']),
                enabled: $response['enabled'] ?? false,
                reason: EvaluationReason::from($response['reason'] ?? 'server'),
                version: $response['version'] ?? 0
            );

            $this->eventQueue?->trackEvaluation(
                $flagKey,
                $response['value'],
                $mergedContext->stripPrivateAttributes()
            );

            return $result;
        } catch (\Throwable) {
            // Fall back to cache
            return $this->evaluate($flagKey, $context);
        }
    }

    public function getBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): bool
    {
        $result = $this->evaluate($flagKey, $context);
        return $result->reason === EvaluationReason::FlagNotFound ? $defaultValue : $result->getBoolValue();
    }

    public function getStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): string
    {
        $result = $this->evaluate($flagKey, $context);
        return $result->reason === EvaluationReason::FlagNotFound ? $defaultValue : ($result->getStringValue() ?? $defaultValue);
    }

    public function getNumberValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): float
    {
        $result = $this->evaluate($flagKey, $context);
        return $result->reason === EvaluationReason::FlagNotFound ? $defaultValue : $result->getNumberValue();
    }

    public function getIntValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): int
    {
        $result = $this->evaluate($flagKey, $context);
        return $result->reason === EvaluationReason::FlagNotFound ? $defaultValue : $result->getIntValue();
    }

    /**
     * @param array<string, mixed>|null $defaultValue
     * @return array<string, mixed>|null
     */
    public function getJsonValue(string $flagKey, ?array $defaultValue, ?EvaluationContext $context = null): ?array
    {
        $result = $this->evaluate($flagKey, $context);
        return $result->reason === EvaluationReason::FlagNotFound ? $defaultValue : ($result->getJsonValue() ?? $defaultValue);
    }

    /**
     * @return array<string, FlagState>
     */
    public function getAllFlags(): array
    {
        return $this->cache->getAll();
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function track(string $eventType, ?array $data = null): void
    {
        $this->eventQueue?->trackCustom($eventType, $data);
    }

    public function flush(): void
    {
        $this->eventQueue?->flushAll();
    }

    public function pollForUpdates(?string $since = null): void
    {
        $path = $since !== null
            ? "/sdk/updates?since={$since}"
            : '/sdk/updates';

        $response = $this->httpClient->get($path);

        if (isset($response['hasUpdates']) && $response['hasUpdates'] && isset($response['flags'])) {
            foreach ($response['flags'] as $flagData) {
                $flag = FlagState::fromArray($flagData);
                $this->cache->set($flag->key, $flag);
            }
        }
    }

    public function close(): void
    {
        $this->flush();
    }

    private function mergeContext(?EvaluationContext $context): EvaluationContext
    {
        return $this->globalContext->merge($context);
    }

    /**
     * @param array<string, mixed> $bootstrap
     */
    private function loadBootstrap(array $bootstrap): void
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
     * @param AnalyticsEvent[] $events
     */
    private function sendEvents(array $events): void
    {
        $this->httpClient->post('/sdk/events/batch', [
            'events' => array_map(fn($e) => $e->toArray(), $events),
        ]);
    }
}
