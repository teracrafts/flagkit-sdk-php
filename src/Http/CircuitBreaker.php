<?php

declare(strict_types=1);

namespace FlagKit\Http;

use FlagKit\Error\ErrorCode;
use FlagKit\Error\FlagKitException;

enum CircuitState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}

class CircuitBreaker
{
    private CircuitState $state = CircuitState::Closed;
    private int $failureCount = 0;
    private ?int $openedAt = null;

    public function __construct(
        private readonly int $threshold = 5,
        private readonly int $resetTimeout = 30
    ) {
    }

    public function getState(): CircuitState
    {
        if ($this->state === CircuitState::Open && $this->openedAt !== null) {
            if (time() - $this->openedAt >= $this->resetTimeout) {
                $this->state = CircuitState::HalfOpen;
            }
        }
        return $this->state;
    }

    public function isOpen(): bool
    {
        return $this->getState() === CircuitState::Open;
    }

    public function isClosed(): bool
    {
        return $this->getState() === CircuitState::Closed;
    }

    public function isHalfOpen(): bool
    {
        return $this->getState() === CircuitState::HalfOpen;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function canExecute(): bool
    {
        $state = $this->getState();
        return $state === CircuitState::Closed || $state === CircuitState::HalfOpen;
    }

    public function recordSuccess(): void
    {
        $this->failureCount = 0;
        $this->state = CircuitState::Closed;
        $this->openedAt = null;
    }

    public function recordFailure(): void
    {
        $this->failureCount++;

        if ($this->state === CircuitState::HalfOpen || $this->failureCount >= $this->threshold) {
            $this->state = CircuitState::Open;
            $this->openedAt = time();
        }
    }

    public function reset(): void
    {
        $this->state = CircuitState::Closed;
        $this->failureCount = 0;
        $this->openedAt = null;
    }

    /**
     * @template T
     * @param callable(): T $action
     * @param callable(): T|null $fallback
     * @return T
     * @throws FlagKitException
     */
    public function execute(callable $action, ?callable $fallback = null): mixed
    {
        if (!$this->canExecute()) {
            if ($fallback !== null) {
                return $fallback();
            }

            throw FlagKitException::networkError(
                ErrorCode::HttpCircuitOpen,
                'Circuit breaker is open'
            );
        }

        try {
            $result = $action();
            $this->recordSuccess();
            return $result;
        } catch (FlagKitException $e) {
            if ($e->getErrorCode() !== ErrorCode::HttpCircuitOpen) {
                $this->recordFailure();
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->recordFailure();
            throw $e;
        }
    }
}
