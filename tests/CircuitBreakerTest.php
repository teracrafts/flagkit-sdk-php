<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Http\CircuitBreaker;
use FlagKit\Http\CircuitState;
use FlagKit\Error\FlagKitException;
use FlagKit\Error\ErrorCode;
use PHPUnit\Framework\TestCase;

class CircuitBreakerTest extends TestCase
{
    public function testInitialStateIsClosed(): void
    {
        $breaker = new CircuitBreaker();

        $this->assertEquals(CircuitState::Closed, $breaker->getState());
        $this->assertTrue($breaker->isClosed());
        $this->assertTrue($breaker->canExecute());
    }

    public function testOpensAfterThresholdFailures(): void
    {
        $breaker = new CircuitBreaker(threshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $this->assertTrue($breaker->isClosed());

        $breaker->recordFailure();
        $this->assertTrue($breaker->isOpen());
        $this->assertFalse($breaker->canExecute());
    }

    public function testSuccessResetsFailureCount(): void
    {
        $breaker = new CircuitBreaker(threshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordSuccess();

        $this->assertEquals(0, $breaker->getFailureCount());
        $this->assertTrue($breaker->isClosed());
    }

    public function testTransitionsToHalfOpenAfterResetTimeout(): void
    {
        $breaker = new CircuitBreaker(threshold: 1, resetTimeout: 1);

        $breaker->recordFailure();
        $this->assertTrue($breaker->isOpen());

        sleep(2);

        $this->assertTrue($breaker->isHalfOpen());
        $this->assertTrue($breaker->canExecute());
    }

    public function testSuccessInHalfOpenClosesCircuit(): void
    {
        $breaker = new CircuitBreaker(threshold: 1, resetTimeout: 1);

        $breaker->recordFailure();
        sleep(2);

        $breaker->recordSuccess();

        $this->assertTrue($breaker->isClosed());
    }

    public function testFailureInHalfOpenOpensCircuit(): void
    {
        $breaker = new CircuitBreaker(threshold: 1, resetTimeout: 1);

        $breaker->recordFailure();
        sleep(2);

        $this->assertTrue($breaker->isHalfOpen());
        $breaker->recordFailure();

        $this->assertTrue($breaker->isOpen());
    }

    public function testResetReturnsToClosed(): void
    {
        $breaker = new CircuitBreaker(threshold: 1);

        $breaker->recordFailure();
        $this->assertTrue($breaker->isOpen());

        $breaker->reset();

        $this->assertTrue($breaker->isClosed());
        $this->assertEquals(0, $breaker->getFailureCount());
    }

    public function testExecuteRecordsSuccess(): void
    {
        $breaker = new CircuitBreaker();

        $result = $breaker->execute(fn() => 'success');

        $this->assertEquals('success', $result);
        $this->assertEquals(0, $breaker->getFailureCount());
    }

    public function testExecuteRecordsFailureOnException(): void
    {
        $breaker = new CircuitBreaker(threshold: 5);

        try {
            $breaker->execute(fn() => throw new \RuntimeException('test'));
        } catch (\RuntimeException) {
            // Expected
        }

        $this->assertEquals(1, $breaker->getFailureCount());
    }

    public function testExecuteUsesFallbackWhenOpen(): void
    {
        $breaker = new CircuitBreaker(threshold: 1);
        $breaker->recordFailure();

        $result = $breaker->execute(
            fn() => 'primary',
            fn() => 'fallback'
        );

        $this->assertEquals('fallback', $result);
    }

    public function testExecuteThrowsWhenOpenWithoutFallback(): void
    {
        $breaker = new CircuitBreaker(threshold: 1);
        $breaker->recordFailure();

        $this->expectException(FlagKitException::class);

        $breaker->execute(fn() => 'value');
    }
}
