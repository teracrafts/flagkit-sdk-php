<?php

declare(strict_types=1);

namespace FlagKit;

class FlagState
{
    public function __construct(
        public readonly string $key,
        public readonly FlagValue $value,
        public readonly bool $enabled = true,
        public readonly int $version = 0,
        public readonly ?FlagType $flagType = null,
        public readonly ?string $lastModified = null,
        /** @var array<string, string>|null */
        public readonly ?array $metadata = null
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            value: FlagValue::from($data['value']),
            enabled: $data['enabled'] ?? true,
            version: $data['version'] ?? 0,
            flagType: isset($data['flagType']) ? FlagType::from($data['flagType']) : null,
            lastModified: $data['lastModified'] ?? null,
            metadata: $data['metadata'] ?? null
        );
    }

    public function getEffectiveFlagType(): FlagType
    {
        return $this->flagType ?? $this->value->getInferredType();
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

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value->getRaw(),
            'enabled' => $this->enabled,
            'version' => $this->version,
            'flagType' => $this->flagType?->value,
            'lastModified' => $this->lastModified,
            'metadata' => $this->metadata,
        ];
    }
}
