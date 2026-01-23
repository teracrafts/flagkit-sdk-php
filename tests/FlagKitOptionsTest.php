<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\FlagKitOptions;
use FlagKit\Error\FlagKitException;
use FlagKit\Error\ErrorCode;
use PHPUnit\Framework\TestCase;

class FlagKitOptionsTest extends TestCase
{
    public function testValidOptionsPassValidation(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $exception = null;
        try {
            $options->validate();
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testMissingApiKeyThrows(): void
    {
        $options = new FlagKitOptions(apiKey: '');

        $this->expectException(FlagKitException::class);
        $options->validate();
    }

    public function testInvalidApiKeyPrefixThrows(): void
    {
        $options = new FlagKitOptions(apiKey: 'invalid_key');

        $this->expectException(FlagKitException::class);
        $options->validate();
    }

    /**
     * @dataProvider validPrefixProvider
     */
    public function testValidApiKeyPrefixesPass(string $prefix): void
    {
        $options = new FlagKitOptions(apiKey: $prefix . 'test123');

        $exception = null;
        try {
            $options->validate();
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public static function validPrefixProvider(): array
    {
        return [
            ['sdk_'],
            ['srv_'],
            ['cli_'],
        ];
    }

    public function testInvalidBaseUrlThrows(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            baseUrl: 'not-a-url'
        );

        $this->expectException(FlagKitException::class);
        $options->validate();
    }

    public function testZeroPollingIntervalThrows(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            pollingInterval: 0
        );

        $this->expectException(FlagKitException::class);
        $options->validate();
    }

    public function testNegativeCacheTtlThrows(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            cacheTtl: -1
        );

        $this->expectException(FlagKitException::class);
        $options->validate();
    }

    public function testBuilderCreatesValidOptions(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->baseUrl('https://custom.api.com')
            ->pollingInterval(60)
            ->cacheTtl(600)
            ->maxCacheSize(500)
            ->cacheEnabled(true)
            ->eventBatchSize(20)
            ->eventFlushInterval(60)
            ->eventsEnabled(true)
            ->timeout(30)
            ->retryAttempts(5)
            ->build();

        $this->assertEquals('sdk_test123', $options->apiKey);
        $this->assertEquals('https://custom.api.com', $options->baseUrl);
        $this->assertEquals(60, $options->pollingInterval);
        $this->assertEquals(600, $options->cacheTtl);
        $this->assertEquals(500, $options->maxCacheSize);
        $this->assertTrue($options->cacheEnabled);
        $this->assertEquals(20, $options->eventBatchSize);
        $this->assertEquals(60, $options->eventFlushInterval);
        $this->assertTrue($options->eventsEnabled);
        $this->assertEquals(30, $options->timeout);
        $this->assertEquals(5, $options->retryAttempts);
    }

    public function testBuilderWithBootstrapData(): void
    {
        $bootstrap = [
            'flag1' => true,
            'flag2' => 'value',
        ];

        $options = FlagKitOptions::builder('sdk_test123')
            ->bootstrap($bootstrap)
            ->build();

        $this->assertNotNull($options->bootstrap);
        $this->assertCount(2, $options->bootstrap);
    }

    public function testDefaultValuesAreSet(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $this->assertEquals(FlagKitOptions::DEFAULT_BASE_URL, $options->baseUrl);
        $this->assertEquals(FlagKitOptions::DEFAULT_POLLING_INTERVAL, $options->pollingInterval);
        $this->assertEquals(FlagKitOptions::DEFAULT_CACHE_TTL, $options->cacheTtl);
        $this->assertEquals(FlagKitOptions::DEFAULT_MAX_CACHE_SIZE, $options->maxCacheSize);
        $this->assertTrue($options->cacheEnabled);
        $this->assertEquals(FlagKitOptions::DEFAULT_EVENT_BATCH_SIZE, $options->eventBatchSize);
        $this->assertEquals(FlagKitOptions::DEFAULT_EVENT_FLUSH_INTERVAL, $options->eventFlushInterval);
        $this->assertTrue($options->eventsEnabled);
        $this->assertEquals(FlagKitOptions::DEFAULT_TIMEOUT, $options->timeout);
        $this->assertEquals(FlagKitOptions::DEFAULT_RETRY_ATTEMPTS, $options->retryAttempts);
    }
}
