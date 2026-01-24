<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Error\ErrorCode;
use FlagKit\Error\FlagKitException;
use FlagKit\Http\Retry;
use FlagKit\Http\RetryConfig;
use FlagKit\Http\RetryResult;
use PHPUnit\Framework\TestCase;

class RetryTest extends TestCase
{
    public function testExecuteSucceedsOnFirstAttempt(): void
    {
        $retry = new Retry();
        $callCount = 0;

        $result = $retry->execute(function () use (&$callCount) {
            $callCount++;
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(1, $callCount);
    }

    public function testExecuteRetriesOnFailure(): void
    {
        $retry = new Retry(new RetryConfig(
            maxAttempts: 3,
            baseDelayMs: 10,
            maxDelayMs: 100
        ));
        $callCount = 0;

        $result = $retry->execute(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw FlagKitException::networkError(ErrorCode::HttpNetworkError, 'Network error');
            }
            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(3, $callCount);
    }

    public function testExecuteThrowsAfterMaxAttempts(): void
    {
        $retry = new Retry(new RetryConfig(
            maxAttempts: 2,
            baseDelayMs: 10
        ));

        $this->expectException(FlagKitException::class);

        $retry->execute(function () {
            throw FlagKitException::networkError(ErrorCode::HttpNetworkError, 'Network error');
        });
    }

    public function testExecuteDoesNotRetryNonRetryableErrors(): void
    {
        $retry = new Retry();
        $callCount = 0;

        $this->expectException(FlagKitException::class);

        try {
            $retry->execute(function () use (&$callCount) {
                $callCount++;
                throw FlagKitException::configError(ErrorCode::ConfigInvalidApiKey, 'Invalid API key');
            });
        } finally {
            $this->assertEquals(1, $callCount);
        }
    }

    public function testExecuteWithResultReturnsSuccessResult(): void
    {
        $retry = new Retry();

        $result = $retry->executeWithResult(function () {
            return 'success';
        });

        $this->assertTrue($result->success);
        $this->assertEquals('success', $result->value);
        $this->assertNull($result->error);
        $this->assertEquals(1, $result->attempts);
    }

    public function testExecuteWithResultReturnsFailureResult(): void
    {
        $retry = new Retry(new RetryConfig(
            maxAttempts: 2,
            baseDelayMs: 10
        ));

        $result = $retry->executeWithResult(function () {
            throw FlagKitException::networkError(ErrorCode::HttpNetworkError, 'Network error');
        });

        $this->assertFalse($result->success);
        $this->assertNull($result->value);
        $this->assertInstanceOf(FlagKitException::class, $result->error);
        $this->assertEquals(2, $result->attempts);
    }

    public function testCalculateBackoffIncreasesExponentially(): void
    {
        $config = new RetryConfig(
            baseDelayMs: 100,
            maxDelayMs: 10000,
            backoffMultiplier: 2.0,
            jitterMs: 0
        );
        $retry = new Retry($config);

        $delay1 = $retry->calculateBackoff(1);
        $delay2 = $retry->calculateBackoff(2);
        $delay3 = $retry->calculateBackoff(3);

        $this->assertEquals(100, $delay1);
        $this->assertEquals(200, $delay2);
        $this->assertEquals(400, $delay3);
    }

    public function testCalculateBackoffCapsAtMaxDelay(): void
    {
        $config = new RetryConfig(
            baseDelayMs: 1000,
            maxDelayMs: 5000,
            backoffMultiplier: 10.0,
            jitterMs: 0
        );
        $retry = new Retry($config);

        $delay = $retry->calculateBackoff(5);

        $this->assertEquals(5000, $delay);
    }

    public function testOnRetryCallbackIsCalled(): void
    {
        $retry = new Retry(new RetryConfig(
            maxAttempts: 3,
            baseDelayMs: 10
        ));
        $retryCalls = [];

        $retry->onRetry(function ($attempt, $error, $delay) use (&$retryCalls) {
            $retryCalls[] = ['attempt' => $attempt, 'delay' => $delay];
        });

        $callCount = 0;
        $retry->execute(function () use (&$callCount) {
            $callCount++;
            if ($callCount < 3) {
                throw FlagKitException::networkError(ErrorCode::HttpNetworkError, 'Network error');
            }
            return 'success';
        });

        $this->assertCount(2, $retryCalls);
        $this->assertEquals(1, $retryCalls[0]['attempt']);
        $this->assertEquals(2, $retryCalls[1]['attempt']);
    }

    public function testCustomShouldRetryCallback(): void
    {
        $retry = new Retry(new RetryConfig(maxAttempts: 5, baseDelayMs: 10));
        $callCount = 0;

        // Only retry if the exception message contains "please_retry"
        $retry->setShouldRetry(function (\Throwable $e) {
            return str_contains($e->getMessage(), 'please_retry');
        });

        $exceptionThrown = false;
        try {
            $retry->execute(function () use (&$callCount) {
                $callCount++;
                throw new \RuntimeException('fatal error - no recovery');
            });
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);
        $this->assertEquals(1, $callCount);
    }

    public function testParseRetryAfterWithSeconds(): void
    {
        $this->assertEquals(30, Retry::parseRetryAfter('30'));
        $this->assertEquals(120, Retry::parseRetryAfter('120'));
    }

    public function testParseRetryAfterWithInvalidInput(): void
    {
        $this->assertNull(Retry::parseRetryAfter(null));
        $this->assertNull(Retry::parseRetryAfter(''));
        $this->assertNull(Retry::parseRetryAfter('invalid'));
    }

    public function testRetryConfigDefaults(): void
    {
        $config = RetryConfig::default();

        $this->assertEquals(3, $config->maxAttempts);
        $this->assertEquals(1000, $config->baseDelayMs);
        $this->assertEquals(30000, $config->maxDelayMs);
        $this->assertEquals(2.0, $config->backoffMultiplier);
        $this->assertEquals(100, $config->jitterMs);
    }

    public function testRetryConfigWithMethods(): void
    {
        $config = RetryConfig::default()
            ->withMaxAttempts(5)
            ->withBaseDelay(500)
            ->withMaxDelay(10000);

        $this->assertEquals(5, $config->maxAttempts);
        $this->assertEquals(500, $config->baseDelayMs);
        $this->assertEquals(10000, $config->maxDelayMs);
    }
}
