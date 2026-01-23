<?php

declare(strict_types=1);

namespace FlagKit\Types;

use DateTimeImmutable;

class EvaluationResult
{
    public function __construct(
        public readonly string $flagKey,
        public readonly FlagValue $value,
        public readonly bool $enabled = false,
        public readonly EvaluationReason $reason = EvaluationReason::Default,
        public readonly int $version = 0,
        public readonly DateTimeImmutable $timestamp = new DateTimeImmutable()
    ) {
    }

    public function getBoolValue(): bool
    {
        return $this->value->getBool() ?? false;
    }

    public function getStringValue(): ?string
    {
        return $this->value->getString();
    }

    public function getNumberValue(): float
    {
        return $this->value->getNumber() ?? 0.0;
    }

    public function getIntValue(): int
    {
        return $this->value->getInt() ?? 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getJsonValue(): ?array
    {
        return $this->value->getArray();
    }

    public static function defaultResult(
        string $key,
        FlagValue $defaultValue,
        EvaluationReason $reason
    ): self {
        return new self(
            flagKey: $key,
            value: $defaultValue,
            enabled: false,
            reason: $reason
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'flagKey' => $this->flagKey,
            'value' => $this->value->getRaw(),
            'enabled' => $this->enabled,
            'reason' => $this->reason->value,
            'version' => $this->version,
            'timestamp' => $this->timestamp->format(DateTimeImmutable::ATOM),
        ];
    }
}
