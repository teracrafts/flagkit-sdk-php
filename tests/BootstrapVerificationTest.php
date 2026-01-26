<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Error\SecurityException;
use FlagKit\FlagKitClient;
use FlagKit\FlagKitOptions;
use FlagKit\Utils\Security;
use PHPUnit\Framework\TestCase;

class BootstrapVerificationTest extends TestCase
{
    private const TEST_API_KEY = 'sdk_test_key_12345678';

    // ==================== Security::canonicalizeObject ====================

    public function testCanonicalizeObjectSortsKeysAlphabetically(): void
    {
        $obj = [
            'zebra' => 1,
            'apple' => 2,
            'mango' => 3,
        ];

        $canonical = Security::canonicalizeObject($obj);
        $expected = '{"apple":2,"mango":3,"zebra":1}';

        $this->assertEquals($expected, $canonical);
    }

    public function testCanonicalizeObjectSortsNestedObjects(): void
    {
        $obj = [
            'outer' => [
                'zebra' => 1,
                'apple' => 2,
            ],
            'alpha' => true,
        ];

        $canonical = Security::canonicalizeObject($obj);
        $expected = '{"alpha":true,"outer":{"apple":2,"zebra":1}}';

        $this->assertEquals($expected, $canonical);
    }

    public function testCanonicalizeObjectPreservesArrayOrder(): void
    {
        $obj = [
            'items' => [3, 1, 2],
        ];

        $canonical = Security::canonicalizeObject($obj);
        $expected = '{"items":[3,1,2]}';

        $this->assertEquals($expected, $canonical);
    }

    // ==================== Security::verifyBootstrapSignature ====================

    public function testVerifyBootstrapSignatureAcceptsValidSignature(): void
    {
        $flags = ['feature1' => true, 'feature2' => 'value'];
        $timestamp = (int) (microtime(true) * 1000);

        // Create signature
        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testVerifyBootstrapSignatureRejectsInvalidSignature(): void
    {
        $flags = ['feature1' => true];
        $timestamp = (int) (microtime(true) * 1000);

        $bootstrap = [
            'flags' => $flags,
            'signature' => 'invalid_signature_here',
            'timestamp' => $timestamp,
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid bootstrap signature', $result['error']);
    }

    public function testVerifyBootstrapSignatureRejectsExpiredTimestamp(): void
    {
        $flags = ['feature1' => true];
        // 48 hours ago (beyond default 24h max age)
        $timestamp = (int) ((microtime(true) - 172800) * 1000);

        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Bootstrap timestamp has expired', $result['error']);
    }

    public function testVerifyBootstrapSignatureRejectsFutureTimestamp(): void
    {
        $flags = ['feature1' => true];
        // 1 hour in the future
        $timestamp = (int) ((microtime(true) + 3600) * 1000);

        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Bootstrap timestamp is in the future', $result['error']);
    }

    public function testVerifyBootstrapSignatureAcceptsLegacyFormat(): void
    {
        // Legacy format has no signature
        $bootstrap = [
            'feature1' => true,
            'feature2' => 'value',
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testVerifyBootstrapSignatureSkipsWhenDisabled(): void
    {
        $bootstrap = [
            'flags' => ['feature1' => true],
            'signature' => 'invalid_signature',
            'timestamp' => (int) (microtime(true) * 1000),
        ];

        $result = Security::verifyBootstrapSignature(
            $bootstrap,
            self::TEST_API_KEY,
            enabled: false
        );

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testVerifyBootstrapSignatureSkipsWhenOnFailureIgnore(): void
    {
        $bootstrap = [
            'flags' => ['feature1' => true],
            'signature' => 'invalid_signature',
            'timestamp' => (int) (microtime(true) * 1000),
        ];

        $result = Security::verifyBootstrapSignature(
            $bootstrap,
            self::TEST_API_KEY,
            enabled: true,
            maxAge: 86400000,
            onFailure: 'ignore'
        );

        $this->assertTrue($result['valid']);
        $this->assertNull($result['error']);
    }

    public function testVerifyBootstrapSignatureRejectsMissingFlags(): void
    {
        $bootstrap = [
            'signature' => 'some_signature',
            'timestamp' => (int) (microtime(true) * 1000),
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Bootstrap missing flags field', $result['error']);
    }

    public function testVerifyBootstrapSignatureRejectsMissingTimestamp(): void
    {
        $bootstrap = [
            'flags' => ['feature1' => true],
            'signature' => 'some_signature',
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Bootstrap missing or invalid timestamp', $result['error']);
    }

    public function testVerifyBootstrapSignatureRespectsCustomMaxAge(): void
    {
        $flags = ['feature1' => true];
        // 2 hours ago
        $timestamp = (int) ((microtime(true) - 7200) * 1000);

        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        // With 1 hour max age, should fail
        $result = Security::verifyBootstrapSignature(
            $bootstrap,
            self::TEST_API_KEY,
            maxAge: 3600000 // 1 hour
        );

        $this->assertFalse($result['valid']);
        $this->assertEquals('Bootstrap timestamp has expired', $result['error']);

        // With 3 hour max age, should pass
        $result = Security::verifyBootstrapSignature(
            $bootstrap,
            self::TEST_API_KEY,
            maxAge: 10800000 // 3 hours
        );

        $this->assertTrue($result['valid']);
    }

    // ==================== FlagKitClient Bootstrap Integration ====================

    public function testClientLoadsValidSignedBootstrap(): void
    {
        $flags = ['test_flag' => true, 'another_flag' => 'hello'];
        $timestamp = (int) (microtime(true) * 1000);

        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        $options = new FlagKitOptions(
            apiKey: self::TEST_API_KEY,
            bootstrap: $bootstrap,
            eventsEnabled: false
        );

        $client = new FlagKitClient($options);

        $this->assertTrue($client->hasFlag('test_flag'));
        $this->assertTrue($client->hasFlag('another_flag'));
        $this->assertTrue($client->getBooleanValue('test_flag', false));
        $this->assertEquals('hello', $client->getStringValue('another_flag', 'default'));
    }

    public function testClientRejectsInvalidSignatureWithErrorMode(): void
    {
        $bootstrap = [
            'flags' => ['test_flag' => true],
            'signature' => 'invalid_signature',
            'timestamp' => (int) (microtime(true) * 1000),
        ];

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Bootstrap verification failed');

        $options = new FlagKitOptions(
            apiKey: self::TEST_API_KEY,
            bootstrap: $bootstrap,
            eventsEnabled: false,
            bootstrapVerificationOnFailure: 'error'
        );

        new FlagKitClient($options);
    }

    public function testClientWarnsOnInvalidSignatureWithWarnMode(): void
    {
        $bootstrap = [
            'flags' => ['test_flag' => true],
            'signature' => 'invalid_signature',
            'timestamp' => (int) (microtime(true) * 1000),
        ];

        $errorCalled = false;
        $capturedError = null;

        $options = new FlagKitOptions(
            apiKey: self::TEST_API_KEY,
            bootstrap: $bootstrap,
            eventsEnabled: false,
            bootstrapVerificationOnFailure: 'warn'
        );

        $client = new FlagKitClient($options);
        $client->onError(function ($error) use (&$errorCalled, &$capturedError) {
            $errorCalled = true;
            $capturedError = $error;
        });

        // Flags should NOT be loaded with warn mode on verification failure
        $this->assertFalse($client->hasFlag('test_flag'));
    }

    public function testClientIgnoresVerificationWithIgnoreMode(): void
    {
        $bootstrap = [
            'flags' => ['test_flag' => true],
            'signature' => 'invalid_signature',
            'timestamp' => (int) (microtime(true) * 1000),
        ];

        $options = new FlagKitOptions(
            apiKey: self::TEST_API_KEY,
            bootstrap: $bootstrap,
            eventsEnabled: false,
            bootstrapVerificationOnFailure: 'ignore'
        );

        $client = new FlagKitClient($options);

        // With ignore mode, flags should be loaded despite invalid signature
        $this->assertTrue($client->hasFlag('test_flag'));
    }

    public function testClientLoadsLegacyBootstrapFormat(): void
    {
        // Legacy format: direct key-value pairs
        $bootstrap = [
            'feature1' => true,
            'feature2' => 'value',
            'feature3' => 42,
        ];

        $options = new FlagKitOptions(
            apiKey: self::TEST_API_KEY,
            bootstrap: $bootstrap,
            eventsEnabled: false
        );

        $client = new FlagKitClient($options);

        $this->assertTrue($client->hasFlag('feature1'));
        $this->assertTrue($client->hasFlag('feature2'));
        $this->assertTrue($client->hasFlag('feature3'));
        $this->assertTrue($client->getBooleanValue('feature1', false));
        $this->assertEquals('value', $client->getStringValue('feature2', ''));
        $this->assertEquals(42, $client->getNumberValue('feature3', 0));
    }

    public function testClientDisablesVerificationWithOption(): void
    {
        $bootstrap = [
            'flags' => ['test_flag' => true],
            'signature' => 'invalid_signature',
            'timestamp' => (int) (microtime(true) * 1000),
        ];

        $options = new FlagKitOptions(
            apiKey: self::TEST_API_KEY,
            bootstrap: $bootstrap,
            eventsEnabled: false,
            bootstrapVerificationEnabled: false
        );

        $client = new FlagKitClient($options);

        // Verification disabled, so flags should load
        $this->assertTrue($client->hasFlag('test_flag'));
    }

    // ==================== FlagKitOptions Validation ====================

    public function testOptionsValidatesBootstrapVerificationMaxAge(): void
    {
        $this->expectException(\FlagKit\Error\FlagKitException::class);
        $this->expectExceptionMessage('Bootstrap verification max age must be positive');

        $options = new FlagKitOptions(
            apiKey: self::TEST_API_KEY,
            bootstrapVerificationMaxAge: 0
        );

        $options->validate();
    }

    public function testOptionsValidatesBootstrapVerificationOnFailure(): void
    {
        $this->expectException(\FlagKit\Error\FlagKitException::class);
        $this->expectExceptionMessage('Bootstrap verification on failure must be one of');

        $options = new FlagKitOptions(
            apiKey: self::TEST_API_KEY,
            bootstrapVerificationOnFailure: 'invalid_option'
        );

        $options->validate();
    }

    public function testOptionsBuilderSetsBootstrapVerificationOptions(): void
    {
        $options = FlagKitOptions::builder(self::TEST_API_KEY)
            ->bootstrapVerificationEnabled(false)
            ->bootstrapVerificationMaxAge(3600000)
            ->bootstrapVerificationOnFailure('error')
            ->build();

        $this->assertFalse($options->bootstrapVerificationEnabled);
        $this->assertEquals(3600000, $options->bootstrapVerificationMaxAge);
        $this->assertEquals('error', $options->bootstrapVerificationOnFailure);
    }

    // ==================== Edge Cases ====================

    public function testSignatureVerificationWithComplexFlags(): void
    {
        $flags = [
            'simple_bool' => true,
            'simple_string' => 'hello',
            'simple_number' => 42,
            'nested_json' => [
                'level1' => [
                    'level2' => 'deep value',
                ],
                'array' => [1, 2, 3],
            ],
        ];

        $timestamp = (int) (microtime(true) * 1000);
        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertTrue($result['valid']);
    }

    public function testSignatureVerificationWithUnicodeFlags(): void
    {
        $flags = [
            'greeting_en' => 'Hello',
            'greeting_zh' => "\u4f60\u597d",
            'greeting_emoji' => 'Hi! :)',
        ];

        $timestamp = (int) (microtime(true) * 1000);
        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertTrue($result['valid']);
    }

    public function testSignatureVerificationWithEmptyFlags(): void
    {
        $flags = [];

        $timestamp = (int) (microtime(true) * 1000);
        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertTrue($result['valid']);
    }

    public function testTamperedFlagsAreDetected(): void
    {
        $originalFlags = ['feature1' => true];
        $timestamp = (int) (microtime(true) * 1000);

        // Sign with original flags
        $canonicalizedFlags = Security::canonicalizeObject($originalFlags);
        $message = "{$timestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        // Tamper with flags
        $bootstrap = [
            'flags' => ['feature1' => false], // Changed from true to false
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid bootstrap signature', $result['error']);
    }

    public function testTamperedTimestampIsDetected(): void
    {
        $flags = ['feature1' => true];
        $originalTimestamp = (int) (microtime(true) * 1000);

        // Sign with original timestamp
        $canonicalizedFlags = Security::canonicalizeObject($flags);
        $message = "{$originalTimestamp}.{$canonicalizedFlags}";
        $signature = Security::generateHMACSHA256($message, self::TEST_API_KEY);

        // Tamper with timestamp (change to a past time to avoid future timestamp check)
        $bootstrap = [
            'flags' => $flags,
            'signature' => $signature,
            'timestamp' => $originalTimestamp - 1000, // Changed timestamp to 1 second earlier
        ];

        $result = Security::verifyBootstrapSignature($bootstrap, self::TEST_API_KEY);

        $this->assertFalse($result['valid']);
        $this->assertEquals('Invalid bootstrap signature', $result['error']);
    }
}
