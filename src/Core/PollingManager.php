<?php

declare(strict_types=1);

namespace FlagKit\Core;

/**
 * Configuration for the polling manager.
 */
class PollingConfig
{
    public const DEFAULT_INTERVAL_SECONDS = 30;
    public const DEFAULT_JITTER_SECONDS = 1;
    public const DEFAULT_BACKOFF_MULTIPLIER = 2.0;
    public const DEFAULT_MAX_INTERVAL_SECONDS = 300; // 5 minutes

    public function __construct(
        /** Polling interval in seconds */
        public readonly int $interval = self::DEFAULT_INTERVAL_SECONDS,
        /** Jitter in seconds to prevent thundering herd */
        public readonly int $jitter = self::DEFAULT_JITTER_SECONDS,
        /** Backoff multiplier for errors */
        public readonly float $backoffMultiplier = self::DEFAULT_BACKOFF_MULTIPLIER,
        /** Maximum interval in seconds */
        public readonly int $maxInterval = self::DEFAULT_MAX_INTERVAL_SECONDS
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public function withInterval(int $seconds): self
    {
        return new self(
            $seconds,
            $this->jitter,
            $this->backoffMultiplier,
            $this->maxInterval
        );
    }

    public function withJitter(int $seconds): self
    {
        return new self(
            $this->interval,
            $seconds,
            $this->backoffMultiplier,
            $this->maxInterval
        );
    }

    public function withBackoffMultiplier(float $multiplier): self
    {
        return new self(
            $this->interval,
            $this->jitter,
            $multiplier,
            $this->maxInterval
        );
    }

    public function withMaxInterval(int $seconds): self
    {
        return new self(
            $this->interval,
            $this->jitter,
            $this->backoffMultiplier,
            $seconds
        );
    }
}

/**
 * Polling state enumeration.
 */
enum PollingState: string
{
    case Stopped = 'stopped';
    case Running = 'running';
    case Paused = 'paused';
}

/**
 * Manages background polling for flag updates.
 *
 * Features:
 * - Configurable polling interval
 * - Jitter to prevent thundering herd
 * - Exponential backoff on errors
 *
 * Note: PHP is typically synchronous, so this manager provides
 * the infrastructure for polling but actual scheduling depends
 * on the runtime environment (e.g., ReactPHP, Swoole, or cron).
 */
class PollingManager
{
    private PollingConfig $config;

    /** @var callable(): void */
    private $onPoll;

    /** Current polling interval in seconds */
    private int $currentInterval;

    /** Current state */
    private PollingState $state = PollingState::Stopped;

    /** Consecutive error count */
    private int $consecutiveErrors = 0;

    /** Last poll timestamp */
    private ?int $lastPollAt = null;

    /** Last successful poll timestamp */
    private ?int $lastSuccessAt = null;

    /** Last error message */
    private ?string $lastError = null;

    /**
     * @param callable(): void $onPoll Callback to execute on each poll
     */
    public function __construct(callable $onPoll, ?PollingConfig $config = null)
    {
        $this->onPoll = $onPoll;
        $this->config = $config ?? PollingConfig::default();
        $this->currentInterval = $this->config->interval;
    }

    /**
     * Start the polling manager.
     * Note: This sets the state to running but doesn't actually start a background loop.
     * Use poll() or pollNow() to perform polling operations.
     */
    public function start(): void
    {
        if ($this->state === PollingState::Running) {
            return;
        }

        $this->state = PollingState::Running;
    }

    /**
     * Stop the polling manager.
     */
    public function stop(): void
    {
        if ($this->state === PollingState::Stopped) {
            return;
        }

        $this->state = PollingState::Stopped;
    }

    /**
     * Pause the polling manager.
     */
    public function pause(): void
    {
        if ($this->state !== PollingState::Running) {
            return;
        }

        $this->state = PollingState::Paused;
    }

    /**
     * Resume the polling manager from paused state.
     */
    public function resume(): void
    {
        if ($this->state !== PollingState::Paused) {
            return;
        }

        $this->state = PollingState::Running;
    }

    /**
     * Check if polling is active (running or paused).
     */
    public function isActive(): bool
    {
        return $this->state === PollingState::Running;
    }

    /**
     * Check if polling is running.
     */
    public function isRunning(): bool
    {
        return $this->state === PollingState::Running;
    }

    /**
     * Get current state.
     */
    public function getState(): PollingState
    {
        return $this->state;
    }

    /**
     * Get current polling interval in seconds.
     */
    public function getCurrentInterval(): int
    {
        return $this->currentInterval;
    }

    /**
     * Get the configured base interval in seconds.
     */
    public function getBaseInterval(): int
    {
        return $this->config->interval;
    }

    /**
     * Get the number of consecutive errors.
     */
    public function getConsecutiveErrors(): int
    {
        return $this->consecutiveErrors;
    }

    /**
     * Get the last poll timestamp.
     */
    public function getLastPollAt(): ?int
    {
        return $this->lastPollAt;
    }

    /**
     * Get the last successful poll timestamp.
     */
    public function getLastSuccessAt(): ?int
    {
        return $this->lastSuccessAt;
    }

    /**
     * Get the last error message.
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Check if polling should occur now based on the interval.
     */
    public function shouldPollNow(): bool
    {
        if ($this->state !== PollingState::Running) {
            return false;
        }

        if ($this->lastPollAt === null) {
            return true;
        }

        $elapsed = time() - $this->lastPollAt;
        return $elapsed >= $this->getNextDelay();
    }

    /**
     * Calculate next poll delay with jitter.
     *
     * @return int Delay in seconds
     */
    public function getNextDelay(): int
    {
        $jitter = (int) (mt_rand() / mt_getrandmax() * $this->config->jitter);
        return $this->currentInterval + $jitter;
    }

    /**
     * Record a successful poll.
     */
    public function onSuccess(): void
    {
        $this->consecutiveErrors = 0;
        $this->currentInterval = $this->config->interval;
        $this->lastSuccessAt = time();
        $this->lastError = null;
    }

    /**
     * Record a failed poll.
     */
    public function onError(?string $message = null): void
    {
        $this->consecutiveErrors++;
        $this->lastError = $message;

        // Apply exponential backoff
        $this->currentInterval = min(
            (int) ($this->currentInterval * $this->config->backoffMultiplier),
            $this->config->maxInterval
        );
    }

    /**
     * Execute a single poll operation.
     * Returns true if successful, false otherwise.
     */
    public function poll(): bool
    {
        if ($this->state !== PollingState::Running) {
            return false;
        }

        $this->lastPollAt = time();

        try {
            ($this->onPoll)();
            $this->onSuccess();
            return true;
        } catch (\Throwable $e) {
            $this->onError($e->getMessage());
            return false;
        }
    }

    /**
     * Force an immediate poll, regardless of interval.
     * Returns true if successful, false otherwise.
     */
    public function pollNow(): bool
    {
        $previousState = $this->state;

        // Temporarily set to running if stopped
        if ($this->state === PollingState::Stopped) {
            $this->state = PollingState::Running;
        }

        $result = $this->poll();

        // Restore previous state if it was stopped
        if ($previousState === PollingState::Stopped) {
            $this->state = PollingState::Stopped;
        }

        return $result;
    }

    /**
     * Reset the polling manager to initial state.
     */
    public function reset(): void
    {
        $this->consecutiveErrors = 0;
        $this->currentInterval = $this->config->interval;
        $this->lastError = null;

        // Keep timestamps for history but allow immediate poll
        $this->lastPollAt = null;
    }

    /**
     * Get statistics about the polling manager.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'state' => $this->state->value,
            'currentInterval' => $this->currentInterval,
            'baseInterval' => $this->config->interval,
            'maxInterval' => $this->config->maxInterval,
            'consecutiveErrors' => $this->consecutiveErrors,
            'lastPollAt' => $this->lastPollAt,
            'lastSuccessAt' => $this->lastSuccessAt,
            'lastError' => $this->lastError,
            'nextPollIn' => $this->lastPollAt !== null
                ? max(0, $this->getNextDelay() - (time() - $this->lastPollAt))
                : 0,
        ];
    }

    /**
     * Update configuration.
     */
    public function setConfig(PollingConfig $config): void
    {
        $this->config = $config;

        // Reset current interval if it's larger than the new max
        if ($this->currentInterval > $config->maxInterval) {
            $this->currentInterval = $config->maxInterval;
        }

        // Reset to base interval if current is less than new base
        if ($this->currentInterval < $config->interval) {
            $this->currentInterval = $config->interval;
        }
    }

    /**
     * Create a blocking polling loop.
     * Use with caution - this will block the current process.
     *
     * @param int $maxIterations Maximum number of poll iterations (0 for infinite)
     * @param callable|null $onIteration Callback after each iteration, return false to stop
     */
    public function runBlockingLoop(int $maxIterations = 0, ?callable $onIteration = null): void
    {
        $this->start();
        $iteration = 0;

        while ($this->state === PollingState::Running) {
            if ($maxIterations > 0 && $iteration >= $maxIterations) {
                break;
            }

            if ($this->shouldPollNow()) {
                $this->poll();
                $iteration++;

                if ($onIteration !== null && ($onIteration)($iteration, $this) === false) {
                    break;
                }
            }

            // Sleep for a short interval before checking again
            usleep(100000); // 100ms
        }

        $this->stop();
    }
}
