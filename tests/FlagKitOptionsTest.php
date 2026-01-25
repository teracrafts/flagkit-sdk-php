<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\FlagKitOptions;
use FlagKit\Error\FlagKitException;
use FlagKit\Error\ErrorCode;
use FlagKit\Error\SecurityException;
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

    // ==================== Secondary API Key Tests ====================

    public function testSecondaryApiKeyIsNullByDefault(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $this->assertNull($options->secondaryApiKey);
    }

    public function testSecondaryApiKeyCanBeSet(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_primary_key',
            secondaryApiKey: 'sdk_secondary_key'
        );

        $this->assertEquals('sdk_secondary_key', $options->secondaryApiKey);
    }

    public function testInvalidSecondaryApiKeyThrows(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_primary_key',
            secondaryApiKey: 'invalid_key'
        );

        $this->expectException(FlagKitException::class);
        $options->validate();
    }

    public function testBuilderWithSecondaryApiKey(): void
    {
        $options = FlagKitOptions::builder('sdk_primary')
            ->secondaryApiKey('sdk_secondary')
            ->build();

        $this->assertEquals('sdk_secondary', $options->secondaryApiKey);
    }

    // ==================== Key Rotation Grace Period Tests ====================

    public function testDefaultKeyRotationGracePeriod(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $this->assertEquals(FlagKitOptions::DEFAULT_KEY_ROTATION_GRACE_PERIOD, $options->keyRotationGracePeriod);
    }

    public function testCustomKeyRotationGracePeriod(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            keyRotationGracePeriod: 600
        );

        $this->assertEquals(600, $options->keyRotationGracePeriod);
    }

    public function testBuilderWithKeyRotationGracePeriod(): void
    {
        $options = FlagKitOptions::builder('sdk_test')
            ->keyRotationGracePeriod(120)
            ->build();

        $this->assertEquals(120, $options->keyRotationGracePeriod);
    }

    // ==================== Strict PII Mode Tests ====================

    public function testStrictPIIModeIsFalseByDefault(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $this->assertFalse($options->strictPIIMode);
    }

    public function testStrictPIIModeCanBeEnabled(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            strictPIIMode: true
        );

        $this->assertTrue($options->strictPIIMode);
    }

    public function testBuilderWithStrictPIIMode(): void
    {
        $options = FlagKitOptions::builder('sdk_test')
            ->strictPIIMode(true)
            ->build();

        $this->assertTrue($options->strictPIIMode);
    }

    // ==================== Request Signing Tests ====================

    public function testEnableRequestSigningIsTrueByDefault(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $this->assertTrue($options->enableRequestSigning);
    }

    public function testEnableRequestSigningCanBeDisabled(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            enableRequestSigning: false
        );

        $this->assertFalse($options->enableRequestSigning);
    }

    public function testBuilderWithEnableRequestSigning(): void
    {
        $options = FlagKitOptions::builder('sdk_test')
            ->enableRequestSigning(false)
            ->build();

        $this->assertFalse($options->enableRequestSigning);
    }

    // ==================== Cache Encryption Tests ====================

    public function testEnableCacheEncryptionIsFalseByDefault(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123');

        $this->assertFalse($options->enableCacheEncryption);
    }

    public function testEnableCacheEncryptionCanBeEnabled(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test123',
            enableCacheEncryption: true
        );

        $this->assertTrue($options->enableCacheEncryption);
    }

    public function testBuilderWithEnableCacheEncryption(): void
    {
        $options = FlagKitOptions::builder('sdk_test')
            ->enableCacheEncryption(true)
            ->build();

        $this->assertTrue($options->enableCacheEncryption);
    }

    // ==================== LocalPort in Production Tests ====================

    public function testLocalPortInProductionThrowsSecurityException(): void
    {
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=production');

        try {
            $options = new FlagKitOptions(
                apiKey: 'sdk_test123',
                localPort: 3000
            );

            $this->expectException(SecurityException::class);
            $options->validate();
        } finally {
            // Restore original
            if ($originalEnv !== false) {
                putenv("APP_ENV={$originalEnv}");
            } else {
                putenv('APP_ENV');
            }
        }
    }

    public function testLocalPortInDevelopmentDoesNotThrow(): void
    {
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=development');

        try {
            $options = new FlagKitOptions(
                apiKey: 'sdk_test123',
                localPort: 3000
            );

            $exception = null;
            try {
                $options->validate();
            } catch (\Throwable $e) {
                $exception = $e;
            }

            $this->assertNull($exception);
        } finally {
            // Restore original
            if ($originalEnv !== false) {
                putenv("APP_ENV={$originalEnv}");
            } else {
                putenv('APP_ENV');
            }
        }
    }

    // ==================== Combined Builder Tests ====================

    public function testBuilderWithAllSecurityOptions(): void
    {
        $options = FlagKitOptions::builder('sdk_primary')
            ->secondaryApiKey('sdk_secondary')
            ->keyRotationGracePeriod(600)
            ->strictPIIMode(true)
            ->enableRequestSigning(true)
            ->enableCacheEncryption(true)
            ->build();

        $this->assertEquals('sdk_secondary', $options->secondaryApiKey);
        $this->assertEquals(600, $options->keyRotationGracePeriod);
        $this->assertTrue($options->strictPIIMode);
        $this->assertTrue($options->enableRequestSigning);
        $this->assertTrue($options->enableCacheEncryption);
    }
}
