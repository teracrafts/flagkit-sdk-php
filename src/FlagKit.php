<?php

declare(strict_types=1);

namespace FlagKit;

/**
 * Static factory for FlagKit SDK with singleton pattern.
 */
final class FlagKit
{
    private static ?FlagKitClient $instance = null;

    private function __construct()
    {
    }

    public static function getInstance(): FlagKitClient
    {
        if (self::$instance === null) {
            throw FlagKitException::notInitialized();
        }
        return self::$instance;
    }

    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    public static function initialize(FlagKitOptions $options): FlagKitClient
    {
        if (self::$instance !== null) {
            throw FlagKitException::alreadyInitialized();
        }

        self::$instance = new FlagKitClient($options);
        return self::$instance;
    }

    public static function initializeAndStart(FlagKitOptions $options): FlagKitClient
    {
        $client = self::initialize($options);
        $client->initialize();
        return $client;
    }

    public static function close(): void
    {
        self::$instance?->close();
        self::$instance = null;
    }

    // Convenience methods that delegate to getInstance()

    /**
     * @param array<string, mixed>|null $attributes
     */
    public static function identify(string $userId, ?array $attributes = null): void
    {
        self::getInstance()->identify($userId, $attributes);
    }

    public static function setContext(EvaluationContext $context): void
    {
        self::getInstance()->setContext($context);
    }

    public static function clearContext(): void
    {
        self::getInstance()->clearContext();
    }

    public static function evaluate(string $flagKey, ?EvaluationContext $context = null): EvaluationResult
    {
        return self::getInstance()->evaluate($flagKey, $context);
    }

    public static function getBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): bool
    {
        return self::getInstance()->getBooleanValue($flagKey, $defaultValue, $context);
    }

    public static function getStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): string
    {
        return self::getInstance()->getStringValue($flagKey, $defaultValue, $context);
    }

    public static function getNumberValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): float
    {
        return self::getInstance()->getNumberValue($flagKey, $defaultValue, $context);
    }

    public static function getIntValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): int
    {
        return self::getInstance()->getIntValue($flagKey, $defaultValue, $context);
    }

    /**
     * @param array<string, mixed>|null $defaultValue
     * @return array<string, mixed>|null
     */
    public static function getJsonValue(string $flagKey, ?array $defaultValue, ?EvaluationContext $context = null): ?array
    {
        return self::getInstance()->getJsonValue($flagKey, $defaultValue, $context);
    }

    /**
     * @return array<string, FlagState>
     */
    public static function getAllFlags(): array
    {
        return self::getInstance()->getAllFlags();
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function track(string $eventType, ?array $data = null): void
    {
        self::getInstance()->track($eventType, $data);
    }

    public static function flush(): void
    {
        self::getInstance()->flush();
    }
}
