<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\FlagKitClient;
use FlagKit\FlagKitOptions;
use FlagKit\Error\FlagKitException;
use PHPUnit\Framework\TestCase;

class JitterTest extends TestCase
{
    // ==================== Jitter Disabled Tests ====================

    public function testJitterIsDisabledByDefault(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $this->assertFalse($options->evaluationJitterEnabled);
    }

    public function testJitterNotAppliedWhenDisabled(): void
    {
        // Compare timing with jitter disabled vs enabled to ensure jitter adds delay
        $optionsWithoutJitter = FlagKitOptions::builder('sdk_test123')
            ->evaluationJitterEnabled(false)
            ->bootstrap(['test_flag' => true])
            ->build();

        $optionsWithJitter = FlagKitOptions::builder('sdk_test123')
            ->evaluationJitterEnabled(true)
            ->evaluationJitterMinMs(50) // Use larger values for reliable testing
            ->evaluationJitterMaxMs(100)
            ->bootstrap(['test_flag' => true])
            ->build();

        $clientWithoutJitter = new FlagKitClient($optionsWithoutJitter);
        $clientWithJitter = new FlagKitClient($optionsWithJitter);

        // Measure without jitter
        $startTime = hrtime(true);
        $clientWithoutJitter->getBooleanValue('test_flag', false);
        $endTime = hrtime(true);
        $timeWithoutJitter = ($endTime - $startTime) / 1_000_000;

        // Measure with jitter
        $startTime = hrtime(true);
        $clientWithJitter->getBooleanValue('test_flag', false);
        $endTime = hrtime(true);
        $timeWithJitter = ($endTime - $startTime) / 1_000_000;

        // With jitter should be significantly slower (at least 50ms more)
        $this->assertGreaterThan(
            $timeWithoutJitter + 40, // Allow some margin
            $timeWithJitter,
            'Evaluation with jitter should be slower than without'
        );
    }

    // ==================== Jitter Enabled Tests ====================

    public function testJitterCanBeEnabled(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            evaluationJitterEnabled: true
        );

        $this->assertTrue($options->evaluationJitterEnabled);
    }

    public function testJitterAppliedWhenEnabled(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->evaluationJitterEnabled(true)
            ->evaluationJitterMinMs(5)
            ->evaluationJitterMaxMs(15)
            ->bootstrap(['test_flag' => true])
            ->build();

        $client = new FlagKitClient($options);

        // Measure time for multiple evaluations with jitter
        $iterations = 5;
        $startTime = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $client->getBooleanValue('test_flag', false);
        }

        $endTime = hrtime(true);
        $totalTimeMs = ($endTime - $startTime) / 1_000_000;
        $avgTimeMs = $totalTimeMs / $iterations;

        // With jitter enabled (5-15ms), average should be at least 5ms
        $this->assertGreaterThanOrEqual(5, $avgTimeMs, 'Evaluation with jitter should take at least min jitter time');
    }

    // ==================== Timing Range Tests ====================

    public function testJitterTimingFallsWithinMinMaxRange(): void
    {
        $minMs = 50;
        $maxMs = 100;

        $options = FlagKitOptions::builder('sdk_test123')
            ->evaluationJitterEnabled(true)
            ->evaluationJitterMinMs($minMs)
            ->evaluationJitterMaxMs($maxMs)
            ->bootstrap(['test_flag' => true])
            ->build();

        $client = new FlagKitClient($options);

        // Run multiple evaluations and check timing
        $iterations = 5;
        $timings = [];

        for ($i = 0; $i < $iterations; $i++) {
            $startTime = hrtime(true);
            $client->getBooleanValue('test_flag', false);
            $endTime = hrtime(true);
            $timings[] = ($endTime - $startTime) / 1_000_000;
        }

        // Each timing should be at least minMs (with some tolerance for overhead)
        foreach ($timings as $timing) {
            $this->assertGreaterThanOrEqual(
                $minMs - 5, // Allow small tolerance for timing precision
                $timing,
                'Each evaluation should take at least min jitter time'
            );
        }

        // Average should be at least minMs
        $avgTiming = array_sum($timings) / count($timings);
        $this->assertGreaterThanOrEqual($minMs - 5, $avgTiming);
    }

    // ==================== Custom Min/Max Tests ====================

    public function testCustomMinMaxValuesAreRespected(): void
    {
        $customMin = 20;
        $customMax = 30;

        $options = FlagKitOptions::builder('sdk_test123')
            ->evaluationJitterEnabled(true)
            ->evaluationJitterMinMs($customMin)
            ->evaluationJitterMaxMs($customMax)
            ->bootstrap(['test_flag' => true])
            ->build();

        $this->assertEquals($customMin, $options->evaluationJitterMinMs);
        $this->assertEquals($customMax, $options->evaluationJitterMaxMs);

        $client = new FlagKitClient($options);

        // Measure a single evaluation
        $startTime = hrtime(true);
        $client->getBooleanValue('test_flag', false);
        $endTime = hrtime(true);
        $timeMs = ($endTime - $startTime) / 1_000_000;

        // Time should be at least customMin
        $this->assertGreaterThanOrEqual(
            $customMin - 1, // Allow 1ms tolerance
            $timeMs,
            'Evaluation should respect custom min jitter'
        );
    }

    public function testDefaultJitterValues(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $this->assertEquals(
            FlagKitOptions::DEFAULT_EVALUATION_JITTER_MIN_MS,
            $options->evaluationJitterMinMs
        );
        $this->assertEquals(
            FlagKitOptions::DEFAULT_EVALUATION_JITTER_MAX_MS,
            $options->evaluationJitterMaxMs
        );
    }

    // ==================== Validation Tests ====================

    public function testNegativeMinJitterThrows(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            evaluationJitterMinMs: -1
        );

        $this->expectException(FlagKitException::class);
        $options->validate();
    }

    public function testMaxJitterLessThanMinThrows(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            evaluationJitterMinMs: 20,
            evaluationJitterMaxMs: 10
        );

        $this->expectException(FlagKitException::class);
        $options->validate();
    }

    public function testEqualMinMaxIsValid(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            evaluationJitterEnabled: true,
            evaluationJitterMinMs: 10,
            evaluationJitterMaxMs: 10
        );

        $exception = null;
        try {
            $options->validate();
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'Equal min and max jitter values should be valid');
    }

    // ==================== Builder Tests ====================

    public function testBuilderWithJitterOptions(): void
    {
        $options = FlagKitOptions::builder('sdk_test')
            ->evaluationJitterEnabled(true)
            ->evaluationJitterMinMs(10)
            ->evaluationJitterMaxMs(25)
            ->build();

        $this->assertTrue($options->evaluationJitterEnabled);
        $this->assertEquals(10, $options->evaluationJitterMinMs);
        $this->assertEquals(25, $options->evaluationJitterMaxMs);
    }
}
