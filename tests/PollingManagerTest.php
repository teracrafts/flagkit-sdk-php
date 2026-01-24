<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Core\PollingConfig;
use FlagKit\Core\PollingManager;
use FlagKit\Core\PollingState;
use PHPUnit\Framework\TestCase;

class PollingManagerTest extends TestCase
{
    public function testStartSetsStateToRunning(): void
    {
        $manager = new PollingManager(fn() => null);

        $manager->start();

        $this->assertTrue($manager->isRunning());
        $this->assertEquals(PollingState::Running, $manager->getState());
    }

    public function testStopSetsStateToStopped(): void
    {
        $manager = new PollingManager(fn() => null);
        $manager->start();

        $manager->stop();

        $this->assertFalse($manager->isRunning());
        $this->assertEquals(PollingState::Stopped, $manager->getState());
    }

    public function testPauseAndResume(): void
    {
        $manager = new PollingManager(fn() => null);
        $manager->start();

        $manager->pause();
        $this->assertEquals(PollingState::Paused, $manager->getState());

        $manager->resume();
        $this->assertEquals(PollingState::Running, $manager->getState());
    }

    public function testPollExecutesCallback(): void
    {
        $called = false;
        $manager = new PollingManager(function () use (&$called) {
            $called = true;
        });
        $manager->start();

        $manager->poll();

        $this->assertTrue($called);
    }

    public function testPollNowWorks(): void
    {
        $called = false;
        $manager = new PollingManager(function () use (&$called) {
            $called = true;
        });
        // Not started

        $manager->pollNow();

        $this->assertTrue($called);
    }

    public function testOnSuccessResetsInterval(): void
    {
        $config = (new PollingConfig())->withInterval(30);
        $manager = new PollingManager(fn() => null, $config);

        // Simulate some errors
        $manager->onError();
        $manager->onError();

        $this->assertGreaterThan(30, $manager->getCurrentInterval());

        // Now success
        $manager->onSuccess();

        $this->assertEquals(30, $manager->getCurrentInterval());
        $this->assertEquals(0, $manager->getConsecutiveErrors());
    }

    public function testOnErrorIncreasesInterval(): void
    {
        $config = (new PollingConfig())
            ->withInterval(30)
            ->withBackoffMultiplier(2.0)
            ->withMaxInterval(300);
        $manager = new PollingManager(fn() => null, $config);

        $manager->onError();
        $this->assertEquals(60, $manager->getCurrentInterval());

        $manager->onError();
        $this->assertEquals(120, $manager->getCurrentInterval());

        $manager->onError();
        $this->assertEquals(240, $manager->getCurrentInterval());

        // Should cap at max
        $manager->onError();
        $this->assertEquals(300, $manager->getCurrentInterval());
    }

    public function testGetNextDelayIncludesJitter(): void
    {
        $config = (new PollingConfig())
            ->withInterval(30)
            ->withJitter(10);
        $manager = new PollingManager(fn() => null, $config);

        $delay = $manager->getNextDelay();

        $this->assertGreaterThanOrEqual(30, $delay);
        $this->assertLessThanOrEqual(40, $delay);
    }

    public function testResetClearsState(): void
    {
        $config = (new PollingConfig())->withInterval(30);
        $manager = new PollingManager(fn() => null, $config);

        $manager->onError();
        $manager->onError();

        $manager->reset();

        $this->assertEquals(30, $manager->getCurrentInterval());
        $this->assertEquals(0, $manager->getConsecutiveErrors());
    }

    public function testGetStatsReturnsCorrectData(): void
    {
        $config = (new PollingConfig())->withInterval(30)->withMaxInterval(300);
        $manager = new PollingManager(fn() => null, $config);
        $manager->start();

        $stats = $manager->getStats();

        $this->assertEquals('running', $stats['state']);
        $this->assertEquals(30, $stats['currentInterval']);
        $this->assertEquals(30, $stats['baseInterval']);
        $this->assertEquals(300, $stats['maxInterval']);
        $this->assertEquals(0, $stats['consecutiveErrors']);
    }

    public function testPollRecordsTimestamps(): void
    {
        $manager = new PollingManager(fn() => null);
        $manager->start();

        $this->assertNull($manager->getLastPollAt());
        $this->assertNull($manager->getLastSuccessAt());

        $manager->poll();

        $this->assertNotNull($manager->getLastPollAt());
        $this->assertNotNull($manager->getLastSuccessAt());
    }

    public function testPollRecordsErrorMessage(): void
    {
        $manager = new PollingManager(function () {
            throw new \RuntimeException('Test error');
        });
        $manager->start();

        $manager->poll();

        $this->assertEquals('Test error', $manager->getLastError());
        $this->assertEquals(1, $manager->getConsecutiveErrors());
    }

    public function testShouldPollNowRespectsInterval(): void
    {
        $config = (new PollingConfig())->withInterval(60)->withJitter(0);
        $manager = new PollingManager(fn() => null, $config);
        $manager->start();

        // First poll should happen immediately
        $this->assertTrue($manager->shouldPollNow());

        // After polling, should wait
        $manager->poll();
        $this->assertFalse($manager->shouldPollNow());
    }

    public function testSetConfigUpdatesConfiguration(): void
    {
        $manager = new PollingManager(fn() => null, new PollingConfig());

        $newConfig = (new PollingConfig())->withInterval(60);
        $manager->setConfig($newConfig);

        $this->assertEquals(60, $manager->getBaseInterval());
    }

    public function testPollingConfigDefaults(): void
    {
        $config = PollingConfig::default();

        $this->assertEquals(30, $config->interval);
        $this->assertEquals(1, $config->jitter);
        $this->assertEquals(2.0, $config->backoffMultiplier);
        $this->assertEquals(300, $config->maxInterval);
    }
}
