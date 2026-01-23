<?php

declare(strict_types=1);

namespace FlagKit;

class FlagValue
{
    public function __construct(
        private readonly mixed $value
    ) {
    }

    public static function from(mixed $value): self
    {
        return new self($value);
    }

    public function getRaw(): mixed
    {
        return $this->value;
    }

    public function getBool(): ?bool
    {
        if (is_bool($this->value)) {
            return $this->value;
        }
        return null;
    }

    public function getString(): ?string
    {
        if (is_string($this->value)) {
            return $this->value;
        }
        if (is_scalar($this->value)) {
            return (string) $this->value;
        }
        return null;
    }

    public function getNumber(): ?float
    {
        if (is_numeric($this->value)) {
            return (float) $this->value;
        }
        return null;
    }

    public function getInt(): ?int
    {
        if (is_numeric($this->value)) {
            return (int) $this->value;
        }
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getArray(): ?array
    {
        if (is_array($this->value)) {
            return $this->value;
        }
        return null;
    }

    public function getInferredType(): FlagType
    {
        return match (true) {
            is_bool($this->value) => FlagType::Boolean,
            is_string($this->value) => FlagType::String,
            is_numeric($this->value) => FlagType::Number,
            is_array($this->value) => FlagType::Json,
            default => FlagType::Json,
        };
    }

    public function isNull(): bool
    {
        return $this->value === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    public function jsonSerialize(): mixed
    {
        return $this->value;
    }
}
