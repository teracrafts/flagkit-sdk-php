<?php

declare(strict_types=1);

namespace FlagKit\Core;

use DateTimeImmutable;
use FlagKit\Types\EvaluationContext;

/**
 * Event type enumeration.
 */
enum EventType: string
{
    case Evaluation = 'evaluation';
    case Custom = 'custom';
    case Identify = 'identify';
    case PageView = 'page_view';
    case Track = 'track';
}

/**
 * Configuration for the event queue.
 */
class EventQueueConfig
{
    public const DEFAULT_BATCH_SIZE = 10;
    public const DEFAULT_FLUSH_INTERVAL = 30;
    public const DEFAULT_MAX_QUEUE_SIZE = 1000;
    public const DEFAULT_MAX_RETRIES = 3;
    public const DEFAULT_SAMPLE_RATE = 1.0;

    public function __construct(
        /** Number of events to batch before sending */
        public readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        /** Flush interval in seconds */
        public readonly int $flushInterval = self::DEFAULT_FLUSH_INTERVAL,
        /** Maximum queue size before dropping oldest events */
        public readonly int $maxQueueSize = self::DEFAULT_MAX_QUEUE_SIZE,
        /** Maximum retry attempts for failed flushes */
        public readonly int $maxRetries = self::DEFAULT_MAX_RETRIES,
        /** Event sampling rate (0.0 to 1.0) */
        public readonly float $sampleRate = self::DEFAULT_SAMPLE_RATE,
        /** Event types that are enabled (empty = all, ['*'] = all) */
        /** @var string[] */
        public readonly array $enabledEventTypes = ['*'],
        /** Event types that are disabled */
        /** @var string[] */
        public readonly array $disabledEventTypes = []
    ) {
    }

    public static function default(): self
    {
        return new self();
    }
}

/**
 * Analytics event structure.
 */
class AnalyticsEvent
{
    public function __construct(
        public readonly EventType $eventType,
        public readonly DateTimeImmutable $timestamp = new DateTimeImmutable(),
        public readonly ?string $flagKey = null,
        public readonly mixed $value = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $context = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $data = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $environmentId = null,
        public readonly ?string $sdkVersion = null,
        public readonly string $sdkLanguage = 'php'
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'eventType' => $this->eventType->value,
            'timestamp' => $this->timestamp->format(DateTimeImmutable::ATOM),
            'flagKey' => $this->flagKey,
            'value' => $this->value,
            'context' => $this->context,
            'eventData' => $this->data,
            'sessionId' => $this->sessionId,
            'environmentId' => $this->environmentId,
            'sdkVersion' => $this->sdkVersion,
            'sdkLanguage' => $this->sdkLanguage,
        ], fn($v) => $v !== null);
    }

    public static function evaluation(
        string $flagKey,
        mixed $value,
        ?array $context = null,
        ?string $sessionId = null,
        ?string $environmentId = null
    ): self {
        return new self(
            eventType: EventType::Evaluation,
            flagKey: $flagKey,
            value: $value,
            context: $context,
            sessionId: $sessionId,
            environmentId: $environmentId
        );
    }

    public static function custom(
        string $eventName,
        ?array $data = null,
        ?string $sessionId = null,
        ?string $environmentId = null
    ): self {
        return new self(
            eventType: EventType::Track,
            data: [
                'eventType' => $eventName,
                'data' => $data,
            ],
            sessionId: $sessionId,
            environmentId: $environmentId
        );
    }

    public static function identify(
        string $userId,
        ?array $attributes = null,
        ?string $sessionId = null,
        ?string $environmentId = null
    ): self {
        return new self(
            eventType: EventType::Identify,
            context: [
                'userId' => $userId,
                'attributes' => $attributes,
            ],
            sessionId: $sessionId,
            environmentId: $environmentId
        );
    }

    public static function pageView(
        string $page,
        ?array $data = null,
        ?string $sessionId = null,
        ?string $environmentId = null
    ): self {
        return new self(
            eventType: EventType::PageView,
            data: [
                'page' => $page,
                'data' => $data,
            ],
            sessionId: $sessionId,
            environmentId: $environmentId
        );
    }
}

/**
 * Manages event batching and delivery.
 *
 * Features:
 * - Batching (default: 10 events or 30 seconds)
 * - Automatic retry on failure
 * - Event sampling
 * - Graceful shutdown
 * - Crash-resilient event persistence (optional)
 */
class EventQueue
{
    /** @var AnalyticsEvent[] */
    private array $queue = [];

    /**
     * Map of event ID to AnalyticsEvent for persistence tracking.
     * @var array<string, AnalyticsEvent>
     */
    private array $eventIdMap = [];

    /** @var callable(AnalyticsEvent[]): void */
    private $onFlush;

    private EventQueueConfig $config;
    private ?string $sessionId = null;
    private ?string $environmentId = null;
    private ?string $sdkVersion = null;

    private bool $isFlushing = false;
    private int $failedFlushCount = 0;
    private ?int $lastFlushAt = null;

    private ?EventPersistence $persistence = null;

    public function __construct(
        int $batchSize = EventQueueConfig::DEFAULT_BATCH_SIZE,
        int $flushInterval = EventQueueConfig::DEFAULT_FLUSH_INTERVAL,
        ?callable $onFlush = null,
        ?EventQueueConfig $config = null,
        ?EventPersistence $persistence = null
    ) {
        $this->config = $config ?? new EventQueueConfig(
            batchSize: $batchSize,
            flushInterval: $flushInterval
        );
        $this->onFlush = $onFlush ?? fn() => null;
        $this->persistence = $persistence;

        // Recover persisted events on initialization
        if ($this->persistence !== null) {
            $this->recoverPersistedEvents();
        }
    }

    /**
     * Set the event persistence handler.
     */
    public function setEventPersistence(EventPersistence $persistence): void
    {
        $this->persistence = $persistence;

        // Recover any persisted events
        $this->recoverPersistedEvents();
    }

    /**
     * Get the event persistence handler.
     */
    public function getEventPersistence(): ?EventPersistence
    {
        return $this->persistence;
    }

    /**
     * Recover persisted events on initialization.
     */
    private function recoverPersistedEvents(): void
    {
        if ($this->persistence === null) {
            return;
        }

        $recoveredEvents = $this->persistence->recover();

        foreach ($recoveredEvents as $persistedEvent) {
            // Recreate AnalyticsEvent from persisted data
            $eventType = EventType::tryFrom($persistedEvent->type);
            if ($eventType === null) {
                continue;
            }

            $event = new AnalyticsEvent(
                eventType: $eventType,
                timestamp: new \DateTimeImmutable('@' . (int)($persistedEvent->timestamp / 1000)),
                flagKey: $persistedEvent->data['flagKey'] ?? null,
                value: $persistedEvent->data['value'] ?? null,
                context: $persistedEvent->data['context'] ?? null,
                data: $persistedEvent->data['eventData'] ?? null,
                sessionId: $persistedEvent->data['sessionId'] ?? null,
                environmentId: $persistedEvent->data['environmentId'] ?? null,
                sdkVersion: $persistedEvent->data['sdkVersion'] ?? null,
                sdkLanguage: $persistedEvent->data['sdkLanguage'] ?? 'php'
            );

            // Add to queue with priority (at the beginning)
            array_unshift($this->queue, $event);
            $this->eventIdMap[$persistedEvent->id] = $event;
        }
    }

    /**
     * Set the session ID for all events.
     */
    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Set the environment ID for all events.
     */
    public function setEnvironmentId(string $environmentId): void
    {
        $this->environmentId = $environmentId;
    }

    /**
     * Set the SDK version for all events.
     */
    public function setSdkVersion(string $sdkVersion): void
    {
        $this->sdkVersion = $sdkVersion;
    }

    /**
     * Get the number of events in the queue.
     */
    public function count(): int
    {
        return count($this->queue);
    }

    /**
     * Alias for count().
     */
    public function getQueueSize(): int
    {
        return $this->count();
    }

    /**
     * Check if the queue is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->queue);
    }

    /**
     * Check if currently flushing.
     */
    public function isFlushing(): bool
    {
        return $this->isFlushing;
    }

    /**
     * Add an event to the queue.
     */
    public function add(AnalyticsEvent $event): void
    {
        $this->enqueue($event);
    }

    /**
     * Enqueue an event.
     */
    public function enqueue(AnalyticsEvent $event): void
    {
        // Apply sampling
        if (!$this->shouldSample()) {
            return;
        }

        // Check if event type is enabled
        if (!$this->isEventTypeEnabled($event->eventType->value)) {
            return;
        }

        // Persist event BEFORE queuing (crash-safe)
        $eventId = null;
        if ($this->persistence !== null) {
            $persistedEvent = $this->persistence->persist($event);
            $eventId = $persistedEvent->id;
            $this->eventIdMap[$eventId] = $event;
        }

        // Enforce max queue size - drop oldest if full
        if (count($this->queue) >= $this->config->maxQueueSize) {
            $droppedEvent = array_shift($this->queue);
            // Remove from eventIdMap if persisted
            if ($droppedEvent !== null) {
                $droppedId = array_search($droppedEvent, $this->eventIdMap, true);
                if ($droppedId !== false) {
                    unset($this->eventIdMap[$droppedId]);
                }
            }
        }

        $this->queue[] = $event;

        // Auto-flush if batch size reached
        if (count($this->queue) >= $this->config->batchSize) {
            $this->flush();
        }
    }

    /**
     * Track a flag evaluation event.
     */
    public function trackEvaluation(
        string $flagKey,
        mixed $value,
        ?EvaluationContext $context = null
    ): void {
        $this->enqueue(new AnalyticsEvent(
            eventType: EventType::Evaluation,
            flagKey: $flagKey,
            value: $value,
            context: $context?->toArray(),
            sessionId: $this->sessionId,
            environmentId: $this->environmentId,
            sdkVersion: $this->sdkVersion
        ));
    }

    /**
     * Track a custom event.
     *
     * @param array<string, mixed>|null $data
     */
    public function trackCustom(string $eventType, ?array $data = null): void
    {
        $this->enqueue(new AnalyticsEvent(
            eventType: EventType::Track,
            data: [
                'eventType' => $eventType,
                'data' => $data,
            ],
            sessionId: $this->sessionId,
            environmentId: $this->environmentId,
            sdkVersion: $this->sdkVersion
        ));
    }

    /**
     * Track an identify event.
     *
     * @param array<string, mixed>|null $attributes
     */
    public function trackIdentify(string $userId, ?array $attributes = null): void
    {
        $this->enqueue(new AnalyticsEvent(
            eventType: EventType::Identify,
            context: [
                'userId' => $userId,
                'attributes' => $attributes,
            ],
            sessionId: $this->sessionId,
            environmentId: $this->environmentId,
            sdkVersion: $this->sdkVersion
        ));
    }

    /**
     * Track a page view event.
     *
     * @param array<string, mixed>|null $data
     */
    public function trackPageView(string $page, ?array $data = null): void
    {
        $this->enqueue(new AnalyticsEvent(
            eventType: EventType::PageView,
            data: [
                'page' => $page,
                'data' => $data,
            ],
            sessionId: $this->sessionId,
            environmentId: $this->environmentId,
            sdkVersion: $this->sdkVersion
        ));
    }

    /**
     * Flush a batch of events.
     */
    public function flush(): void
    {
        if (empty($this->queue) || $this->isFlushing) {
            return;
        }

        $this->isFlushing = true;

        // Get events to send
        $events = array_splice($this->queue, 0, $this->config->batchSize);

        // Get event IDs for the events being sent
        $eventIds = [];
        if ($this->persistence !== null) {
            foreach ($events as $event) {
                $eventId = array_search($event, $this->eventIdMap, true);
                if ($eventId !== false) {
                    $eventIds[] = $eventId;
                }
            }
            // Mark events as sending
            if (!empty($eventIds)) {
                $this->persistence->markSending($eventIds);
            }
        }

        try {
            ($this->onFlush)($events);
            $this->failedFlushCount = 0;
            $this->lastFlushAt = time();

            // Mark events as sent after successful flush
            if ($this->persistence !== null && !empty($eventIds)) {
                $this->persistence->markSent($eventIds);
                // Remove from eventIdMap
                foreach ($eventIds as $eventId) {
                    unset($this->eventIdMap[$eventId]);
                }
            }
        } catch (\Throwable $e) {
            $this->failedFlushCount++;

            // Mark events as pending again on failure
            if ($this->persistence !== null && !empty($eventIds)) {
                $this->persistence->markPending($eventIds);
            }

            // Re-queue events on failure (up to max size)
            $requeue = array_slice($events, 0, $this->config->maxQueueSize - count($this->queue));
            $this->queue = array_merge($requeue, $this->queue);

            // Re-throw if max retries exceeded
            if ($this->failedFlushCount >= $this->config->maxRetries) {
                $this->isFlushing = false;
                throw $e;
            }
        } finally {
            $this->isFlushing = false;
        }
    }

    /**
     * Flush all events in the queue.
     */
    public function flushAll(): void
    {
        while (!empty($this->queue)) {
            $this->flush();
        }
    }

    /**
     * Clear the event queue without sending.
     */
    public function clear(): void
    {
        $this->queue = [];
    }

    /**
     * Alias for clear().
     */
    public function clearQueue(): void
    {
        $this->clear();
    }

    /**
     * Get all queued events (for debugging).
     *
     * @return AnalyticsEvent[]
     */
    public function getQueuedEvents(): array
    {
        return $this->queue;
    }

    /**
     * Stop the event queue (flush remaining events).
     */
    public function stop(): void
    {
        $this->flushAll();

        // Cleanup persistence
        if ($this->persistence !== null) {
            $this->persistence->flush();
            $this->persistence->cleanup();
        }
    }

    /**
     * Close the event queue (alias for stop).
     */
    public function close(): void
    {
        $this->stop();

        if ($this->persistence !== null) {
            $this->persistence->close();
        }
    }

    /**
     * Set the flush callback.
     *
     * @param callable(AnalyticsEvent[]): void $callback
     */
    public function setOnFlush(callable $callback): void
    {
        $this->onFlush = $callback;
    }

    /**
     * Get queue statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'queueSize' => $this->count(),
            'maxQueueSize' => $this->config->maxQueueSize,
            'batchSize' => $this->config->batchSize,
            'failedFlushCount' => $this->failedFlushCount,
            'lastFlushAt' => $this->lastFlushAt,
            'isFlushing' => $this->isFlushing,
            'sampleRate' => $this->config->sampleRate,
        ];
    }

    /**
     * Check if an event type is enabled.
     */
    private function isEventTypeEnabled(string $eventType): bool
    {
        // Check disabled list first
        if (in_array($eventType, $this->config->disabledEventTypes, true)) {
            return false;
        }

        // Check enabled list
        if (in_array('*', $this->config->enabledEventTypes, true) ||
            in_array($eventType, $this->config->enabledEventTypes, true)) {
            return true;
        }

        return false;
    }

    /**
     * Apply sampling to determine if event should be recorded.
     */
    private function shouldSample(): bool
    {
        if ($this->config->sampleRate >= 1.0) {
            return true;
        }
        if ($this->config->sampleRate <= 0.0) {
            return false;
        }
        return (mt_rand() / mt_getrandmax()) < $this->config->sampleRate;
    }
}
