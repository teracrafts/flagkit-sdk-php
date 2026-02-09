<?php

declare(strict_types=1);

namespace FlagKit;

use FlagKit\Error\FlagKitException;
use FlagKit\Types\EvaluationContext;
use FlagKit\Types\EvaluationResult;
use FlagKit\Types\FlagState;

/**
 * Static factory for FlagKit SDK with singleton pattern.
 *
 * This class provides a convenient static interface for the FlagKit SDK.
 * It manages a singleton instance of FlagKitClient and delegates all calls to it.
 *
 * Example usage:
 * ```php
 * // Initialize the SDK
 * FlagKit::initialize(new FlagKitOptions(apiKey: 'sdk_your_api_key'));
 *
 * // Identify a user
 * FlagKit::identify('user-123', ['email' => 'user@example.com']);
 *
 * // Evaluate flags
 * $darkMode = FlagKit::getBooleanValue('dark-mode', false);
 *
 * // Track events
 * FlagKit::track('button_clicked', ['button' => 'signup']);
 *
 * // Cleanup
 * FlagKit::close();
 * ```
 */
final class FlagKit
{
    /** SDK Version */
    public const VERSION = '1.0.4';

    private static ?FlagKitClient $instance = null;

    private function __construct()
    {
    }

    /**
     * Get the singleton client instance.
     *
     * @throws FlagKitException If SDK is not initialized
     */
    public static function getInstance(): FlagKitClient
    {
        if (self::$instance === null) {
            throw FlagKitException::notInitialized();
        }
        return self::$instance;
    }

    /**
     * Check if the SDK is initialized.
     */
    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Check if the SDK is ready (initialized and has fetched flags).
     */
    public static function isReady(): bool
    {
        return self::$instance?->isReady() ?? false;
    }

    /**
     * Initialize the SDK with the given options.
     * Does NOT fetch flags - call initializeAndStart() for that.
     *
     * @throws FlagKitException If SDK is already initialized
     */
    public static function initialize(FlagKitOptions $options): FlagKitClient
    {
        if (self::$instance !== null) {
            throw FlagKitException::alreadyInitialized();
        }

        self::$instance = new FlagKitClient($options);
        return self::$instance;
    }

    /**
     * Initialize the SDK and fetch flags from the server.
     *
     * @throws FlagKitException If SDK is already initialized or initialization fails
     */
    public static function initializeAndStart(FlagKitOptions $options): FlagKitClient
    {
        $client = self::initialize($options);
        $client->initialize();
        return $client;
    }

    /**
     * Wait for the SDK to be ready.
     *
     * @param int $timeoutSeconds Maximum time to wait
     * @throws FlagKitException If timeout exceeded or initialization fails
     */
    public static function waitForReady(int $timeoutSeconds = 10): void
    {
        self::getInstance()->waitForReady($timeoutSeconds);
    }

    /**
     * Force refresh flags from the server.
     */
    public static function refresh(): void
    {
        self::getInstance()->refresh();
    }

    /**
     * Close the SDK and cleanup resources.
     */
    public static function close(): void
    {
        self::$instance?->close();
        self::$instance = null;
    }

    // --------------------------------------------------
    // Context Management
    // --------------------------------------------------

    /**
     * Identify a user.
     *
     * @param array<string, mixed>|null $attributes Additional user attributes
     */
    public static function identify(string $userId, ?array $attributes = null): void
    {
        self::getInstance()->identify($userId, $attributes);
    }

    /**
     * Set the global evaluation context.
     */
    public static function setContext(EvaluationContext $context): void
    {
        self::getInstance()->setContext($context);
    }

    /**
     * Get the current global context.
     */
    public static function getContext(): ?EvaluationContext
    {
        return self::getInstance()->getContext();
    }

    /**
     * Clear the global context.
     */
    public static function clearContext(): void
    {
        self::getInstance()->clearContext();
    }

    /**
     * Reset to anonymous state.
     */
    public static function reset(): void
    {
        self::getInstance()->reset();
    }

    // --------------------------------------------------
    // Flag Evaluation
    // --------------------------------------------------

    /**
     * Evaluate a flag and return the full result.
     */
    public static function evaluate(string $flagKey, ?EvaluationContext $context = null): EvaluationResult
    {
        return self::getInstance()->evaluate($flagKey, $context);
    }

    /**
     * Evaluate all flags.
     *
     * @return array<string, EvaluationResult>
     */
    public static function evaluateAll(?EvaluationContext $context = null): array
    {
        return self::getInstance()->evaluateAll($context);
    }

    /**
     * Get a boolean flag value.
     */
    public static function getBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): bool
    {
        return self::getInstance()->getBooleanValue($flagKey, $defaultValue, $context);
    }

    /**
     * Get a string flag value.
     */
    public static function getStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): string
    {
        return self::getInstance()->getStringValue($flagKey, $defaultValue, $context);
    }

    /**
     * Get a number flag value.
     */
    public static function getNumberValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): float
    {
        return self::getInstance()->getNumberValue($flagKey, $defaultValue, $context);
    }

    /**
     * Get an integer flag value.
     */
    public static function getIntValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): int
    {
        return self::getInstance()->getIntValue($flagKey, $defaultValue, $context);
    }

    /**
     * Get a JSON flag value.
     *
     * @param array<string, mixed>|null $defaultValue
     * @return array<string, mixed>|null
     */
    public static function getJsonValue(string $flagKey, ?array $defaultValue, ?EvaluationContext $context = null): ?array
    {
        return self::getInstance()->getJsonValue($flagKey, $defaultValue, $context);
    }

    /**
     * Check if a flag exists.
     */
    public static function hasFlag(string $flagKey): bool
    {
        return self::getInstance()->hasFlag($flagKey);
    }

    /**
     * Get all flag keys.
     *
     * @return string[]
     */
    public static function getAllFlagKeys(): array
    {
        return self::getInstance()->getAllFlagKeys();
    }

    /**
     * Get all cached flags.
     *
     * @return array<string, FlagState>
     */
    public static function getAllFlags(): array
    {
        return self::getInstance()->getAllFlags();
    }

    // --------------------------------------------------
    // Event Tracking
    // --------------------------------------------------

    /**
     * Track a custom event.
     *
     * @param array<string, mixed>|null $data Event data
     */
    public static function track(string $eventType, ?array $data = null): void
    {
        self::getInstance()->track($eventType, $data);
    }

    /**
     * Flush pending events immediately.
     */
    public static function flush(): void
    {
        self::getInstance()->flush();
    }

    // --------------------------------------------------
    // Polling
    // --------------------------------------------------

    /**
     * Start background polling.
     */
    public static function startPolling(): void
    {
        self::getInstance()->startPolling();
    }

    /**
     * Stop background polling.
     */
    public static function stopPolling(): void
    {
        self::getInstance()->stopPolling();
    }

    /**
     * Check if polling is active.
     */
    public static function isPollingActive(): bool
    {
        return self::getInstance()->isPollingActive();
    }

    /**
     * Manually poll for updates.
     */
    public static function pollForUpdates(?string $since = null): void
    {
        self::getInstance()->pollForUpdates($since);
    }

    // --------------------------------------------------
    // Statistics
    // --------------------------------------------------

    /**
     * Get SDK statistics.
     *
     * @return array<string, mixed>
     */
    public static function getStats(): array
    {
        return self::getInstance()->getStats();
    }
}
