<?php

declare(strict_types=1);

namespace FlagKit\Core;

use DateTimeImmutable;
use FlagKit\Types\EvaluationContext;

enum EventType: string
{
    case Evaluation = 'evaluation';
    case Custom = 'custom';
    case Identify = 'identify';
}

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
        public readonly ?array $data = null
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
            'data' => $this->data,
        ], fn($v) => $v !== null);
    }
}

class EventQueue
{
    /** @var AnalyticsEvent[] */
    private array $queue = [];

    /** @var callable(AnalyticsEvent[]): void */
    private $onFlush;

    public function __construct(
        private readonly int $batchSize = 10,
        private readonly int $flushInterval = 30,
        callable $onFlush = null
    ) {
        $this->onFlush = $onFlush ?? fn() => null;
    }

    public function count(): int
    {
        return count($this->queue);
    }

    public function enqueue(AnalyticsEvent $event): void
    {
        $this->queue[] = $event;

        if (count($this->queue) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function trackEvaluation(
        string $flagKey,
        mixed $value,
        ?EvaluationContext $context = null
    ): void {
        $this->enqueue(new AnalyticsEvent(
            eventType: EventType::Evaluation,
            flagKey: $flagKey,
            value: $value,
            context: $context?->toArray()
        ));
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function trackCustom(string $eventType, ?array $data = null): void
    {
        $this->enqueue(new AnalyticsEvent(
            eventType: EventType::Custom,
            data: [
                'eventType' => $eventType,
                'data' => $data,
            ]
        ));
    }

    /**
     * @param array<string, mixed>|null $attributes
     */
    public function trackIdentify(string $userId, ?array $attributes = null): void
    {
        $this->enqueue(new AnalyticsEvent(
            eventType: EventType::Identify,
            context: [
                'userId' => $userId,
                'attributes' => $attributes,
            ]
        ));
    }

    public function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }

        $events = array_splice($this->queue, 0, $this->batchSize);

        try {
            ($this->onFlush)($events);
        } catch (\Throwable) {
            // Re-queue events on failure
            $this->queue = array_merge($events, $this->queue);
        }
    }

    public function flushAll(): void
    {
        while (!empty($this->queue)) {
            $this->flush();
        }
    }

    /**
     * @param callable(AnalyticsEvent[]): void $callback
     */
    public function setOnFlush(callable $callback): void
    {
        $this->onFlush = $callback;
    }
}
