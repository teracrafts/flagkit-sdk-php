<?php

declare(strict_types=1);

namespace FlagKit\Tests\Http;

use FlagKit\FlagKitOptions;
use FlagKit\Http\HttpClient;
use FlagKit\Utils\Security;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class HttpClientSecurityTest extends TestCase
{
    // ==================== getActiveApiKey ====================

    public function testGetActiveApiKeyReturnsCurrentKey(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_primary_key_123');
        $client = new HttpClient($options);

        $this->assertEquals('sdk_primary_key_123', $client->getActiveApiKey());
    }

    // ==================== getKeyId ====================

    public function testGetKeyIdReturnsFirst8Chars(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_abcdefghijklmnop');
        $client = new HttpClient($options);

        $this->assertEquals('sdk_abcd', $client->getKeyId());
    }

    // ==================== isInKeyRotation ====================

    public function testIsInKeyRotationReturnsFalseInitially(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_primary',
            secondaryApiKey: 'sdk_secondary'
        );
        $client = new HttpClient($options);

        $this->assertFalse($client->isInKeyRotation());
    }

    // ==================== Request Signing Configuration ====================

    public function testRequestSigningEnabledByDefault(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test');

        $this->assertTrue($options->enableRequestSigning);
    }

    public function testRequestSigningCanBeDisabled(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test',
            enableRequestSigning: false
        );

        $this->assertFalse($options->enableRequestSigning);
    }

    // ==================== Key Rotation Configuration ====================

    public function testSecondaryApiKeyConfigured(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_primary_key',
            secondaryApiKey: 'sdk_secondary_key'
        );

        $client = new HttpClient($options);

        $this->assertEquals('sdk_primary_key', $client->getActiveApiKey());
    }

    public function testKeyRotationGracePeriodDefault(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test');

        $this->assertEquals(FlagKitOptions::DEFAULT_KEY_ROTATION_GRACE_PERIOD, $options->keyRotationGracePeriod);
    }

    public function testKeyRotationGracePeriodCustom(): void
    {
        $options = new FlagKitOptions(
            apiKey: 'sdk_test',
            keyRotationGracePeriod: 600
        );

        $this->assertEquals(600, $options->keyRotationGracePeriod);
    }

    // ==================== Base URL Configuration ====================

    public function testGetBaseUrlReturnsProductionUrl(): void
    {
        putenv('FLAGKIT_MODE');
        $baseUrl = HttpClient::getBaseUrl();

        $this->assertEquals('https://api.flagkit.dev/api/v1', $baseUrl);
    }

    public function testGetBaseUrlReturnsLocalUrlWhenModeIsLocal(): void
    {
        putenv('FLAGKIT_MODE=local');
        $baseUrl = HttpClient::getBaseUrl();

        $this->assertEquals('https://api.flagkit.on/api/v1', $baseUrl);
        putenv('FLAGKIT_MODE');
    }

    public function testGetBaseUrlReturnsBetaUrlWhenModeIsBeta(): void
    {
        putenv('FLAGKIT_MODE=beta');
        $baseUrl = HttpClient::getBaseUrl();

        $this->assertEquals('https://api.beta.flagkit.dev/api/v1', $baseUrl);
        putenv('FLAGKIT_MODE');
    }

    public function testGetBaseUrlIsCaseInsensitive(): void
    {
        putenv('FLAGKIT_MODE=LOCAL');
        $baseUrl = HttpClient::getBaseUrl();

        $this->assertEquals('https://api.flagkit.on/api/v1', $baseUrl);
        putenv('FLAGKIT_MODE');
    }

    public function testGetBaseUrlTrimsWhitespace(): void
    {
        putenv('FLAGKIT_MODE= local ');
        $baseUrl = HttpClient::getBaseUrl();

        $this->assertEquals('https://api.flagkit.on/api/v1', $baseUrl);
        putenv('FLAGKIT_MODE');
    }

    public function testGetBaseUrlFallsThroughForUnknownMode(): void
    {
        putenv('FLAGKIT_MODE=staging');
        $baseUrl = HttpClient::getBaseUrl();

        $this->assertEquals('https://api.flagkit.dev/api/v1', $baseUrl);
        putenv('FLAGKIT_MODE');
    }

    // ==================== Logger Integration ====================

    public function testHttpClientAcceptsLogger(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $options = new FlagKitOptions(apiKey: 'sdk_test');

        $client = new HttpClient($options, $mockLogger);

        // No exception means success
        $this->assertInstanceOf(HttpClient::class, $client);
    }

    // ==================== Request Signature Headers ====================

    public function testCreateRequestSignatureFormat(): void
    {
        $body = '{"flagKey": "test", "context": {}}';
        $apiKey = 'sdk_test_key_12345';

        $result = Security::createRequestSignature($body, $apiKey);

        // Verify signature is 64 char hex string (SHA256)
        $this->assertEquals(64, strlen($result['signature']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['signature']);

        // Verify timestamp is a reasonable value
        $this->assertGreaterThan(0, $result['timestamp']);
        $this->assertLessThanOrEqual((int)(microtime(true) * 1000), $result['timestamp']);
    }

    public function testSignatureIsDeterministic(): void
    {
        $body = '{"test": true}';
        $apiKey = 'sdk_test_key';
        $timestamp = 1234567890000;

        $sig1 = Security::createRequestSignature($body, $apiKey, $timestamp);
        $sig2 = Security::createRequestSignature($body, $apiKey, $timestamp);

        $this->assertEquals($sig1['signature'], $sig2['signature']);
    }

    public function testDifferentBodiesProduceDifferentSignatures(): void
    {
        $apiKey = 'sdk_test_key';
        $timestamp = 1234567890000;

        $sig1 = Security::createRequestSignature('{"a": 1}', $apiKey, $timestamp);
        $sig2 = Security::createRequestSignature('{"a": 2}', $apiKey, $timestamp);

        $this->assertNotEquals($sig1['signature'], $sig2['signature']);
    }

    public function testDifferentKeysProduceDifferentSignatures(): void
    {
        $body = '{"test": true}';
        $timestamp = 1234567890000;

        $sig1 = Security::createRequestSignature($body, 'sdk_key_one', $timestamp);
        $sig2 = Security::createRequestSignature($body, 'sdk_key_two', $timestamp);

        $this->assertNotEquals($sig1['signature'], $sig2['signature']);
    }

    public function testDifferentTimestampsProduceDifferentSignatures(): void
    {
        $body = '{"test": true}';
        $apiKey = 'sdk_test_key';

        $sig1 = Security::createRequestSignature($body, $apiKey, 1000000000000);
        $sig2 = Security::createRequestSignature($body, $apiKey, 1000000000001);

        $this->assertNotEquals($sig1['signature'], $sig2['signature']);
    }
}
