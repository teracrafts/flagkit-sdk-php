<?php

declare(strict_types=1);

namespace FlagKit\Core;

use FlagKit\Types\EvaluationContext;
use FlagKit\Types\FlagValue;

/**
 * Manages global and per-evaluation context for flag evaluations.
 *
 * The ContextManager maintains a global context that applies to all evaluations
 * and provides methods for identifying users, merging contexts, and stripping
 * private attributes before sending data to the server.
 */
class ContextManager
{
    private ?EvaluationContext $globalContext = null;

    public function __construct(?EvaluationContext $initialContext = null)
    {
        $this->globalContext = $initialContext;
    }

    /**
     * Set the global context.
     */
    public function setContext(EvaluationContext $context): void
    {
        $this->globalContext = $context;
    }

    /**
     * Get the current global context.
     */
    public function getContext(): ?EvaluationContext
    {
        return $this->globalContext;
    }

    /**
     * Clear the global context.
     */
    public function clearContext(): void
    {
        $this->globalContext = null;
    }

    /**
     * Identify a user by setting userId and optional attributes.
     *
     * @param string $userId The user identifier
     * @param array<string, mixed>|null $attributes Additional attributes to merge
     */
    public function identify(string $userId, ?array $attributes = null): void
    {
        $context = $this->globalContext ?? new EvaluationContext();
        $context = $context->withUserId($userId);

        if ($attributes !== null) {
            $context = $context->withAttributes($attributes);
        }

        // Set anonymous to false when identifying
        $context = $context->withAttribute('anonymous', false);

        $this->globalContext = $context;
    }

    /**
     * Reset to anonymous state.
     */
    public function reset(): void
    {
        $this->globalContext = new EvaluationContext(
            userId: null,
            attributes: ['anonymous' => FlagValue::from(true)]
        );
    }

    /**
     * Resolve context by merging global and evaluation context.
     * Returns context with private attributes stripped.
     */
    public function resolveContext(?EvaluationContext $evaluationContext = null): ?EvaluationContext
    {
        $merged = $this->getMergedContext($evaluationContext);

        if ($merged === null) {
            return null;
        }

        return $merged->stripPrivateAttributes();
    }

    /**
     * Get raw merged context (with private attributes intact).
     */
    public function getMergedContext(?EvaluationContext $evaluationContext = null): ?EvaluationContext
    {
        if ($this->globalContext === null && $evaluationContext === null) {
            return null;
        }

        if ($this->globalContext === null) {
            return $evaluationContext;
        }

        return $this->globalContext->merge($evaluationContext);
    }

    /**
     * Check if a user is identified (has a userId and is not anonymous).
     */
    public function isIdentified(): bool
    {
        if ($this->globalContext === null) {
            return false;
        }

        if ($this->globalContext->userId === null) {
            return false;
        }

        $anonymous = $this->globalContext->get('anonymous');
        if ($anonymous !== null && $anonymous->getBool() === true) {
            return false;
        }

        return true;
    }

    /**
     * Check if user is anonymous.
     */
    public function isAnonymous(): bool
    {
        if ($this->globalContext === null) {
            return true;
        }

        $anonymous = $this->globalContext->get('anonymous');
        return $anonymous?->getBool() === true;
    }

    /**
     * Get current user ID if identified.
     */
    public function getUserId(): ?string
    {
        return $this->globalContext?->userId;
    }

    /**
     * Get a specific attribute from the global context.
     */
    public function getAttribute(string $key): ?FlagValue
    {
        return $this->globalContext?->get($key);
    }

    /**
     * Set a single attribute on the global context.
     */
    public function setAttribute(string $key, mixed $value): void
    {
        if ($this->globalContext === null) {
            $this->globalContext = new EvaluationContext();
        }

        $this->globalContext = $this->globalContext->withAttribute($key, $value);
    }

    /**
     * Set multiple attributes on the global context.
     *
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        if ($this->globalContext === null) {
            $this->globalContext = new EvaluationContext();
        }

        $this->globalContext = $this->globalContext->withAttributes($attributes);
    }

    /**
     * Check if a specific attribute exists.
     */
    public function hasAttribute(string $key): bool
    {
        return $this->globalContext?->get($key) !== null;
    }

    /**
     * Get context statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        if ($this->globalContext === null) {
            return [
                'hasContext' => false,
                'isIdentified' => false,
                'isAnonymous' => true,
                'userId' => null,
                'attributeCount' => 0,
            ];
        }

        return [
            'hasContext' => true,
            'isIdentified' => $this->isIdentified(),
            'isAnonymous' => $this->isAnonymous(),
            'userId' => $this->globalContext->userId,
            'attributeCount' => count($this->globalContext->attributes),
        ];
    }

    /**
     * Create a context builder for fluent context construction.
     */
    public static function builder(): ContextBuilder
    {
        return new ContextBuilder();
    }
}

/**
 * Fluent builder for creating evaluation contexts.
 */
class ContextBuilder
{
    private ?string $userId = null;
    private ?string $email = null;
    private ?string $name = null;
    private bool $anonymous = false;
    private ?string $country = null;
    private ?string $ip = null;
    private ?string $userAgent = null;

    /** @var array<string, mixed> */
    private array $custom = [];

    /** @var array<string> */
    private array $privateAttributes = [];

    public function userId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function email(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function anonymous(bool $anonymous = true): self
    {
        $this->anonymous = $anonymous;
        return $this;
    }

    public function country(string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function ip(string $ip): self
    {
        $this->ip = $ip;
        return $this;
    }

    public function userAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    /**
     * Add a custom attribute.
     */
    public function custom(string $key, mixed $value): self
    {
        $this->custom[$key] = $value;
        return $this;
    }

    /**
     * Add multiple custom attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function customAttributes(array $attributes): self
    {
        $this->custom = array_merge($this->custom, $attributes);
        return $this;
    }

    /**
     * Mark an attribute as private.
     */
    public function privateAttribute(string $attribute): self
    {
        if (!in_array($attribute, $this->privateAttributes, true)) {
            $this->privateAttributes[] = $attribute;
        }
        return $this;
    }

    /**
     * Mark multiple attributes as private.
     *
     * @param array<string> $attributes
     */
    public function privateAttributes(array $attributes): self
    {
        foreach ($attributes as $attribute) {
            $this->privateAttribute($attribute);
        }
        return $this;
    }

    /**
     * Build the evaluation context.
     */
    public function build(): EvaluationContext
    {
        $attributes = [];

        if ($this->email !== null) {
            $attributes['email'] = FlagValue::from($this->email);
        }

        if ($this->name !== null) {
            $attributes['name'] = FlagValue::from($this->name);
        }

        $attributes['anonymous'] = FlagValue::from($this->anonymous);

        if ($this->country !== null) {
            $attributes['country'] = FlagValue::from($this->country);
        }

        if ($this->ip !== null) {
            $attributes['ip'] = FlagValue::from($this->ip);
        }

        if ($this->userAgent !== null) {
            $attributes['userAgent'] = FlagValue::from($this->userAgent);
        }

        if (!empty($this->custom)) {
            $attributes['custom'] = FlagValue::from($this->custom);
        }

        if (!empty($this->privateAttributes)) {
            $attributes['privateAttributes'] = FlagValue::from($this->privateAttributes);
        }

        return new EvaluationContext($this->userId, $attributes);
    }

    /**
     * Build and apply to a context manager.
     */
    public function applyTo(ContextManager $manager): void
    {
        $manager->setContext($this->build());
    }
}
