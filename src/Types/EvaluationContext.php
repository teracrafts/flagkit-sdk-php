<?php

declare(strict_types=1);

namespace FlagKit\Types;

class EvaluationContext
{
    private const PRIVATE_ATTRIBUTE_PREFIX = '_';

    /**
     * @param array<string, FlagValue> $attributes
     */
    public function __construct(
        public readonly ?string $userId = null,
        public readonly array $attributes = []
    ) {
    }

    public function withUserId(string $userId): self
    {
        return new self($userId, $this->attributes);
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $newAttributes = $this->attributes;
        $newAttributes[$key] = FlagValue::from($value);
        return new self($this->userId, $newAttributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $newAttributes = $this->attributes;
        foreach ($attributes as $key => $value) {
            $newAttributes[$key] = FlagValue::from($value);
        }
        return new self($this->userId, $newAttributes);
    }

    public function merge(?self $other): self
    {
        if ($other === null) {
            return $this;
        }

        $mergedAttributes = $this->attributes;
        foreach ($other->attributes as $key => $value) {
            $mergedAttributes[$key] = $value;
        }

        return new self(
            $other->userId ?? $this->userId,
            $mergedAttributes
        );
    }

    public function stripPrivateAttributes(): self
    {
        $filteredAttributes = array_filter(
            $this->attributes,
            fn(string $key) => !str_starts_with($key, self::PRIVATE_ATTRIBUTE_PREFIX),
            ARRAY_FILTER_USE_KEY
        );

        return new self($this->userId, $filteredAttributes);
    }

    public function isEmpty(): bool
    {
        return $this->userId === null && empty($this->attributes);
    }

    public function get(string $key): ?FlagValue
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];
        if ($this->userId !== null) {
            $result['userId'] = $this->userId;
        }
        if (!empty($this->attributes)) {
            $result['attributes'] = array_map(
                fn(FlagValue $v) => $v->getRaw(),
                $this->attributes
            );
        }
        return $result;
    }

    public static function builder(): EvaluationContextBuilder
    {
        return new EvaluationContextBuilder();
    }
}

class EvaluationContextBuilder
{
    private ?string $userId = null;
    /** @var array<string, FlagValue> */
    private array $attributes = [];

    public function userId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function attribute(string $key, mixed $value): self
    {
        $this->attributes[$key] = FlagValue::from($value);
        return $this;
    }

    public function build(): EvaluationContext
    {
        return new EvaluationContext($this->userId, $this->attributes);
    }
}
