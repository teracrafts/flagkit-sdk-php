<?php

declare(strict_types=1);

namespace FlagKit\Tests\Utils;

use FlagKit\Error\SecurityException;
use FlagKit\Utils\EncryptedStorage;
use FlagKit\Utils\PIIDetectionResult;
use FlagKit\Utils\Security;
use FlagKit\Utils\SecurityConfig;
use FlagKit\Utils\SignedPayload;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset additional patterns before each test
        Security::setAdditionalPatterns([]);
    }

    // ==================== isPotentialPIIField ====================

    public function testIsPotentialPIIFieldDetectsEmailFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('email'));
        $this->assertTrue(Security::isPotentialPIIField('userEmail'));
        $this->assertTrue(Security::isPotentialPIIField('EMAIL'));
        $this->assertTrue(Security::isPotentialPIIField('primary_email'));
    }

    public function testIsPotentialPIIFieldDetectsPhoneFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('phone'));
        $this->assertTrue(Security::isPotentialPIIField('phoneNumber'));
        $this->assertTrue(Security::isPotentialPIIField('mobile'));
        $this->assertTrue(Security::isPotentialPIIField('telephone'));
        $this->assertTrue(Security::isPotentialPIIField('PHONE_NUMBER'));
    }

    public function testIsPotentialPIIFieldDetectsSSNFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('ssn'));
        $this->assertTrue(Security::isPotentialPIIField('socialSecurity'));
        $this->assertTrue(Security::isPotentialPIIField('social_security'));
        $this->assertTrue(Security::isPotentialPIIField('SSN_NUMBER'));
    }

    public function testIsPotentialPIIFieldDetectsCreditCardFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('creditCard'));
        $this->assertTrue(Security::isPotentialPIIField('credit_card'));
        $this->assertTrue(Security::isPotentialPIIField('cardNumber'));
        $this->assertTrue(Security::isPotentialPIIField('cvv'));
        $this->assertTrue(Security::isPotentialPIIField('card_number'));
    }

    public function testIsPotentialPIIFieldDetectsAuthenticationFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('password'));
        $this->assertTrue(Security::isPotentialPIIField('passwd'));
        $this->assertTrue(Security::isPotentialPIIField('secret'));
        $this->assertTrue(Security::isPotentialPIIField('apiKey'));
        $this->assertTrue(Security::isPotentialPIIField('api_key'));
        $this->assertTrue(Security::isPotentialPIIField('accessToken'));
        $this->assertTrue(Security::isPotentialPIIField('access_token'));
        $this->assertTrue(Security::isPotentialPIIField('refreshToken'));
        $this->assertTrue(Security::isPotentialPIIField('refresh_token'));
        $this->assertTrue(Security::isPotentialPIIField('authToken'));
        $this->assertTrue(Security::isPotentialPIIField('auth_token'));
        $this->assertTrue(Security::isPotentialPIIField('privateKey'));
        $this->assertTrue(Security::isPotentialPIIField('private_key'));
        $this->assertTrue(Security::isPotentialPIIField('token'));
    }

    public function testIsPotentialPIIFieldDetectsAddressFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('address'));
        $this->assertTrue(Security::isPotentialPIIField('street'));
        $this->assertTrue(Security::isPotentialPIIField('zipCode'));
        $this->assertTrue(Security::isPotentialPIIField('zip_code'));
        $this->assertTrue(Security::isPotentialPIIField('postalCode'));
        $this->assertTrue(Security::isPotentialPIIField('postal_code'));
    }

    public function testIsPotentialPIIFieldDetectsBirthDateFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('dob'));
        $this->assertTrue(Security::isPotentialPIIField('dateOfBirth'));
        $this->assertTrue(Security::isPotentialPIIField('date_of_birth'));
        $this->assertTrue(Security::isPotentialPIIField('birthDate'));
        $this->assertTrue(Security::isPotentialPIIField('birth_date'));
    }

    public function testIsPotentialPIIFieldDetectsIdentificationFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('passport'));
        $this->assertTrue(Security::isPotentialPIIField('driverLicense'));
        $this->assertTrue(Security::isPotentialPIIField('driver_license'));
        $this->assertTrue(Security::isPotentialPIIField('nationalId'));
        $this->assertTrue(Security::isPotentialPIIField('national_id'));
    }

    public function testIsPotentialPIIFieldDetectsBankingFields(): void
    {
        $this->assertTrue(Security::isPotentialPIIField('bankAccount'));
        $this->assertTrue(Security::isPotentialPIIField('bank_account'));
        $this->assertTrue(Security::isPotentialPIIField('routingNumber'));
        $this->assertTrue(Security::isPotentialPIIField('routing_number'));
        $this->assertTrue(Security::isPotentialPIIField('iban'));
        $this->assertTrue(Security::isPotentialPIIField('swift'));
    }

    public function testIsPotentialPIIFieldDoesNotFlagSafeFields(): void
    {
        $this->assertFalse(Security::isPotentialPIIField('userId'));
        $this->assertFalse(Security::isPotentialPIIField('plan'));
        $this->assertFalse(Security::isPotentialPIIField('country'));
        $this->assertFalse(Security::isPotentialPIIField('featureEnabled'));
        $this->assertFalse(Security::isPotentialPIIField('accountId'));
        $this->assertFalse(Security::isPotentialPIIField('isActive'));
        $this->assertFalse(Security::isPotentialPIIField('createdAt'));
    }

    public function testIsPotentialPIIFieldWithAdditionalPatterns(): void
    {
        Security::setAdditionalPatterns(['custom_pii', 'sensitiveField']);

        $this->assertTrue(Security::isPotentialPIIField('custom_pii_data'));
        $this->assertTrue(Security::isPotentialPIIField('mySensitiveField'));
        $this->assertFalse(Security::isPotentialPIIField('normalField'));
    }

    // ==================== detectPotentialPII ====================

    public function testDetectPotentialPIIInFlatObjects(): void
    {
        $data = [
            'userId' => 'user-123',
            'email' => 'user@example.com',
            'plan' => 'premium',
        ];

        $piiFields = Security::detectPotentialPII($data);

        $this->assertContains('email', $piiFields);
        $this->assertNotContains('userId', $piiFields);
        $this->assertNotContains('plan', $piiFields);
    }

    public function testDetectPotentialPIIInNestedObjects(): void
    {
        $data = [
            'user' => [
                'email' => 'user@example.com',
                'phone' => '123-456-7890',
            ],
            'settings' => [
                'darkMode' => true,
            ],
        ];

        $piiFields = Security::detectPotentialPII($data);

        $this->assertContains('user.email', $piiFields);
        $this->assertContains('user.phone', $piiFields);
        $this->assertNotContains('settings.darkMode', $piiFields);
    }

    public function testDetectPotentialPIIInDeeplyNestedObjects(): void
    {
        $data = [
            'profile' => [
                'contact' => [
                    'primaryEmail' => 'user@example.com',
                ],
            ],
        ];

        $piiFields = Security::detectPotentialPII($data);

        $this->assertContains('profile.contact.primaryEmail', $piiFields);
    }

    public function testDetectPotentialPIIReturnsEmptyArrayForSafeData(): void
    {
        $data = [
            'userId' => 'user-123',
            'plan' => 'premium',
            'features' => ['dark-mode', 'beta'],
        ];

        $piiFields = Security::detectPotentialPII($data);

        $this->assertEmpty($piiFields);
    }

    public function testDetectPotentialPIIWithPrefix(): void
    {
        $data = [
            'email' => 'user@example.com',
        ];

        $piiFields = Security::detectPotentialPII($data, 'context');

        $this->assertContains('context.email', $piiFields);
    }

    public function testDetectPotentialPIIIgnoresIndexedArrays(): void
    {
        $data = [
            'tags' => ['email', 'phone'], // These are values, not keys
            'items' => [
                ['name' => 'item1'],
                ['name' => 'item2'],
            ],
        ];

        $piiFields = Security::detectPotentialPII($data);

        $this->assertEmpty($piiFields);
    }

    public function testDetectPotentialPIIMultiplePIIFields(): void
    {
        $data = [
            'email' => 'user@example.com',
            'phone' => '555-1234',
            'ssn' => '123-45-6789',
            'creditCard' => '4111111111111111',
        ];

        $piiFields = Security::detectPotentialPII($data);

        $this->assertCount(4, $piiFields);
        $this->assertContains('email', $piiFields);
        $this->assertContains('phone', $piiFields);
        $this->assertContains('ssn', $piiFields);
        $this->assertContains('creditCard', $piiFields);
    }

    // ==================== warnIfPotentialPII ====================

    public function testWarnIfPotentialPIILogsWarningWhenPIIDetected(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Potential PII detected'));

        $data = [
            'email' => 'user@example.com',
            'phone' => '123-456-7890',
        ];

        Security::warnIfPotentialPII($data, 'context', $mockLogger);
    }

    public function testWarnIfPotentialPIIIncludesFieldNamesInMessage(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('warning')
            ->with($this->logicalAnd(
                $this->stringContains('email'),
                $this->stringContains('phone')
            ));

        $data = [
            'email' => 'user@example.com',
            'phone' => '123-456-7890',
        ];

        Security::warnIfPotentialPII($data, 'context', $mockLogger);
    }

    public function testWarnIfPotentialPIISuggestsPrivateAttributesForContext(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('privateAttributes'));

        $data = ['email' => 'user@example.com'];

        Security::warnIfPotentialPII($data, 'context', $mockLogger);
    }

    public function testWarnIfPotentialPIISuggestsRemovalForEvents(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('removing sensitive data'));

        $data = ['email' => 'user@example.com'];

        Security::warnIfPotentialPII($data, 'event', $mockLogger);
    }

    public function testWarnIfPotentialPIIDoesNotLogWhenNoPIIDetected(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->never())
            ->method('warning');

        $data = [
            'userId' => 'user-123',
            'plan' => 'premium',
        ];

        Security::warnIfPotentialPII($data, 'context', $mockLogger);
    }

    public function testWarnIfPotentialPIIHandlesNullData(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->never())
            ->method('warning');

        Security::warnIfPotentialPII(null, 'event', $mockLogger);
    }

    public function testWarnIfPotentialPIIHandlesNullLogger(): void
    {
        $data = ['email' => 'test@example.com'];

        // Should not throw
        Security::warnIfPotentialPII($data, 'event', null);
        $this->assertTrue(true); // Assertion to confirm no exception
    }

    // ==================== isServerKey ====================

    public function testIsServerKeyReturnsTrueForServerKeys(): void
    {
        $this->assertTrue(Security::isServerKey('srv_abc123'));
        $this->assertTrue(Security::isServerKey('srv_'));
        $this->assertTrue(Security::isServerKey('srv_very_long_key_with_numbers_123456'));
    }

    public function testIsServerKeyReturnsFalseForSDKKeys(): void
    {
        $this->assertFalse(Security::isServerKey('sdk_abc123'));
        $this->assertFalse(Security::isServerKey('sdk_'));
    }

    public function testIsServerKeyReturnsFalseForClientKeys(): void
    {
        $this->assertFalse(Security::isServerKey('cli_abc123'));
        $this->assertFalse(Security::isServerKey('cli_'));
    }

    public function testIsServerKeyReturnsFalseForInvalidKeys(): void
    {
        $this->assertFalse(Security::isServerKey(''));
        $this->assertFalse(Security::isServerKey('invalid_key'));
        $this->assertFalse(Security::isServerKey('SRV_abc123')); // Case sensitive
    }

    // ==================== isClientKey ====================

    public function testIsClientKeyReturnsTrueForSDKKeys(): void
    {
        $this->assertTrue(Security::isClientKey('sdk_abc123'));
        $this->assertTrue(Security::isClientKey('sdk_'));
        $this->assertTrue(Security::isClientKey('sdk_very_long_key_123'));
    }

    public function testIsClientKeyReturnsTrueForCLIKeys(): void
    {
        $this->assertTrue(Security::isClientKey('cli_abc123'));
        $this->assertTrue(Security::isClientKey('cli_'));
        $this->assertTrue(Security::isClientKey('cli_very_long_key_123'));
    }

    public function testIsClientKeyReturnsFalseForServerKeys(): void
    {
        $this->assertFalse(Security::isClientKey('srv_abc123'));
        $this->assertFalse(Security::isClientKey('srv_'));
    }

    public function testIsClientKeyReturnsFalseForInvalidKeys(): void
    {
        $this->assertFalse(Security::isClientKey(''));
        $this->assertFalse(Security::isClientKey('invalid_key'));
        $this->assertFalse(Security::isClientKey('SDK_abc123')); // Case sensitive
    }

    // ==================== warnIfServerKeyInBrowser ====================

    public function testWarnIfServerKeyInBrowserWarnsForServerKeyInBrowserEnv(): void
    {
        // Note: In CLI mode (PHPUnit), isBrowserEnvironment() returns false
        // even with HTTP_USER_AGENT set, because php_sapi_name() is 'cli'.
        // This test verifies the behavior when running in CLI mode.
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0';

        $mockLogger = $this->createMock(LoggerInterface::class);

        // In CLI mode, no warning should be triggered (browser detection returns false)
        if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
            $mockLogger->expects($this->never())
                ->method('warning');
        } else {
            $mockLogger->expects($this->once())
                ->method('warning')
                ->with($this->stringContains('Server keys (srv_) should not be used in browser'));
        }

        Security::warnIfServerKeyInBrowser('srv_abc123', $mockLogger);

        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function testWarnIfServerKeyInBrowserDoesNotWarnForSDKKeys(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0.0.0';

        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->never())
            ->method('warning');

        Security::warnIfServerKeyInBrowser('sdk_abc123', $mockLogger);

        unset($_SERVER['HTTP_USER_AGENT']);
    }

    public function testWarnIfServerKeyInBrowserDoesNotWarnInCLI(): void
    {
        $mockLogger = $this->createMock(LoggerInterface::class);
        $mockLogger->expects($this->never())
            ->method('warning');

        // In CLI mode (which is how PHPUnit runs), this should not warn
        Security::warnIfServerKeyInBrowser('srv_abc123', $mockLogger);
    }

    public function testWarnIfServerKeyInBrowserHandlesNullLogger(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0.0.0';

        // Should not throw even with null logger
        Security::warnIfServerKeyInBrowser('srv_abc123', null);
        $this->assertTrue(true);

        unset($_SERVER['HTTP_USER_AGENT']);
    }

    // ==================== isBrowserEnvironment ====================

    public function testIsBrowserEnvironmentReturnsFalseInCLI(): void
    {
        // PHPUnit runs in CLI mode
        $this->assertFalse(Security::isBrowserEnvironment());
    }

    // ==================== SecurityConfig ====================

    public function testSecurityConfigDefaultValues(): void
    {
        $config = new SecurityConfig();

        $this->assertTrue($config->warnOnPotentialPII);
        $this->assertTrue($config->warnOnServerKeyInBrowser);
        $this->assertEmpty($config->additionalPIIPatterns);
    }

    public function testSecurityConfigCustomValues(): void
    {
        $config = new SecurityConfig(
            warnOnPotentialPII: false,
            warnOnServerKeyInBrowser: false,
            additionalPIIPatterns: ['custom_field', 'another_field']
        );

        $this->assertFalse($config->warnOnPotentialPII);
        $this->assertFalse($config->warnOnServerKeyInBrowser);
        $this->assertCount(2, $config->additionalPIIPatterns);
        $this->assertContains('custom_field', $config->additionalPIIPatterns);
    }

    public function testSecurityConfigDefaultFactory(): void
    {
        $config = SecurityConfig::default();

        $this->assertTrue($config->warnOnPotentialPII);
        $this->assertTrue($config->warnOnServerKeyInBrowser);
    }

    public function testSecurityConfigProductionFactory(): void
    {
        $config = SecurityConfig::production();

        $this->assertFalse($config->warnOnPotentialPII);
        $this->assertTrue($config->warnOnServerKeyInBrowser);
    }

    // ==================== getAllPatterns ====================

    public function testGetAllPatternsReturnsBuiltInPatterns(): void
    {
        $patterns = Security::getAllPatterns();

        $this->assertContains('email', $patterns);
        $this->assertContains('phone', $patterns);
        $this->assertContains('ssn', $patterns);
        $this->assertContains('creditCard', $patterns);
    }

    public function testGetAllPatternsIncludesAdditionalPatterns(): void
    {
        Security::setAdditionalPatterns(['customPattern1', 'customPattern2']);

        $patterns = Security::getAllPatterns();

        $this->assertContains('customPattern1', $patterns);
        $this->assertContains('customPattern2', $patterns);
        // Also still includes built-in
        $this->assertContains('email', $patterns);
    }

    public function testSetAdditionalPatternsReplacesExisting(): void
    {
        Security::setAdditionalPatterns(['pattern1']);
        Security::setAdditionalPatterns(['pattern2', 'pattern3']);

        $patterns = Security::getAllPatterns();

        $this->assertNotContains('pattern1', $patterns);
        $this->assertContains('pattern2', $patterns);
        $this->assertContains('pattern3', $patterns);
    }

    // ==================== checkForPotentialPII ====================

    public function testCheckForPotentialPIIReturnsNoPIIForSafeData(): void
    {
        $data = [
            'userId' => 'user-123',
            'plan' => 'premium',
        ];

        $result = Security::checkForPotentialPII($data, 'context');

        $this->assertFalse($result->hasPII);
        $this->assertEmpty($result->fields);
        $this->assertEmpty($result->message);
    }

    public function testCheckForPotentialPIIDetectsPII(): void
    {
        $data = [
            'email' => 'user@example.com',
            'phone' => '123-456-7890',
        ];

        $result = Security::checkForPotentialPII($data, 'context');

        $this->assertTrue($result->hasPII);
        $this->assertContains('email', $result->fields);
        $this->assertContains('phone', $result->fields);
        $this->assertStringContainsString('privateAttributes', $result->message);
    }

    public function testCheckForPotentialPIIReturnsNoPIIForNull(): void
    {
        $result = Security::checkForPotentialPII(null, 'context');

        $this->assertFalse($result->hasPII);
        $this->assertEmpty($result->fields);
    }

    public function testPIIDetectionResultNoPIIFactory(): void
    {
        $result = PIIDetectionResult::noPII();

        $this->assertFalse($result->hasPII);
        $this->assertEmpty($result->fields);
        $this->assertEmpty($result->message);
    }

    // ==================== isProductionEnvironment ====================

    public function testIsProductionEnvironmentReturnsFalseByDefault(): void
    {
        // Clear APP_ENV if set
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV');

        $result = Security::isProductionEnvironment();

        // Restore original
        if ($originalEnv !== false) {
            putenv("APP_ENV={$originalEnv}");
        }

        $this->assertFalse($result);
    }

    public function testIsProductionEnvironmentReturnsTrueForProduction(): void
    {
        $originalEnv = getenv('APP_ENV');
        putenv('APP_ENV=production');

        $result = Security::isProductionEnvironment();

        // Restore original
        if ($originalEnv !== false) {
            putenv("APP_ENV={$originalEnv}");
        } else {
            putenv('APP_ENV');
        }

        $this->assertTrue($result);
    }

    // ==================== getKeyId ====================

    public function testGetKeyIdReturnsFirst8Characters(): void
    {
        $keyId = Security::getKeyId('sdk_abcdefghijklmnop');

        $this->assertEquals('sdk_abcd', $keyId);
    }

    public function testGetKeyIdHandlesShortKeys(): void
    {
        $keyId = Security::getKeyId('sdk_ab');

        $this->assertEquals('sdk_ab', $keyId);
    }

    // ==================== HMAC-SHA256 Signing ====================

    public function testGenerateHMACSHA256ProducesConsistentSignature(): void
    {
        $message = 'test message';
        $key = 'secret_key';

        $sig1 = Security::generateHMACSHA256($message, $key);
        $sig2 = Security::generateHMACSHA256($message, $key);

        $this->assertEquals($sig1, $sig2);
        $this->assertEquals(64, strlen($sig1)); // SHA256 hex is 64 chars
    }

    public function testGenerateHMACSHA256ProducesDifferentSignaturesForDifferentMessages(): void
    {
        $key = 'secret_key';

        $sig1 = Security::generateHMACSHA256('message1', $key);
        $sig2 = Security::generateHMACSHA256('message2', $key);

        $this->assertNotEquals($sig1, $sig2);
    }

    public function testSignPayloadCreatesValidSignedPayload(): void
    {
        $data = ['key' => 'value', 'number' => 42];
        $apiKey = 'sdk_test_key_12345';

        $signedPayload = Security::signPayload($data, $apiKey);

        $this->assertEquals($data, $signedPayload->data);
        $this->assertNotEmpty($signedPayload->signature);
        $this->assertEquals('sdk_test', $signedPayload->keyId);
        $this->assertGreaterThan(0, $signedPayload->timestamp);
    }

    public function testSignPayloadUsesCustomTimestamp(): void
    {
        $data = ['test' => true];
        $apiKey = 'sdk_test_key';
        $customTimestamp = 1234567890000;

        $signedPayload = Security::signPayload($data, $apiKey, $customTimestamp);

        $this->assertEquals($customTimestamp, $signedPayload->timestamp);
    }

    public function testSignedPayloadToArray(): void
    {
        $signedPayload = new SignedPayload(
            data: ['test' => true],
            signature: 'abc123',
            timestamp: 1234567890,
            keyId: 'sdk_test'
        );

        $array = $signedPayload->toArray();

        $this->assertEquals(['test' => true], $array['data']);
        $this->assertEquals('abc123', $array['signature']);
        $this->assertEquals(1234567890, $array['timestamp']);
        $this->assertEquals('sdk_test', $array['keyId']);
    }

    // ==================== Request Signature ====================

    public function testCreateRequestSignatureReturnsSignatureAndTimestamp(): void
    {
        $body = '{"key": "value"}';
        $apiKey = 'sdk_test_key';

        $result = Security::createRequestSignature($body, $apiKey);

        $this->assertArrayHasKey('signature', $result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertEquals(64, strlen($result['signature']));
        $this->assertGreaterThan(0, $result['timestamp']);
    }

    public function testCreateRequestSignatureWithCustomTimestamp(): void
    {
        $body = '{}';
        $apiKey = 'sdk_test';
        $timestamp = 1000000000000;

        $result = Security::createRequestSignature($body, $apiKey, $timestamp);

        $this->assertEquals($timestamp, $result['timestamp']);
    }

    // ==================== Verify Signed Payload ====================

    public function testVerifySignedPayloadReturnsTrueForValidPayload(): void
    {
        $data = ['key' => 'value'];
        $apiKey = 'sdk_test_key_12345';

        $signedPayload = Security::signPayload($data, $apiKey);
        $isValid = Security::verifySignedPayload($signedPayload, $apiKey);

        $this->assertTrue($isValid);
    }

    public function testVerifySignedPayloadReturnsFalseForWrongKey(): void
    {
        $data = ['key' => 'value'];
        $apiKey = 'sdk_test_key_12345';
        $wrongKey = 'sdk_wrong_key_67890';

        $signedPayload = Security::signPayload($data, $apiKey);
        $isValid = Security::verifySignedPayload($signedPayload, $wrongKey);

        $this->assertFalse($isValid);
    }

    public function testVerifySignedPayloadReturnsFalseForExpiredPayload(): void
    {
        $data = ['key' => 'value'];
        $apiKey = 'sdk_test_key';
        $oldTimestamp = (int) ((microtime(true) - 600) * 1000); // 10 minutes ago

        $signedPayload = Security::signPayload($data, $apiKey, $oldTimestamp);
        $isValid = Security::verifySignedPayload($signedPayload, $apiKey, 300000); // 5 min max age

        $this->assertFalse($isValid);
    }

    public function testVerifySignedPayloadReturnsFalseForTamperedData(): void
    {
        $data = ['key' => 'value'];
        $apiKey = 'sdk_test_key';

        $signedPayload = Security::signPayload($data, $apiKey);

        // Create tampered payload
        $tamperedPayload = new SignedPayload(
            data: ['key' => 'tampered'],
            signature: $signedPayload->signature,
            timestamp: $signedPayload->timestamp,
            keyId: $signedPayload->keyId
        );

        $isValid = Security::verifySignedPayload($tamperedPayload, $apiKey);

        $this->assertFalse($isValid);
    }

    // ==================== Encrypted Storage ====================

    public function testEncryptedStorageEncryptsAndDecrypts(): void
    {
        $apiKey = 'sdk_test_key_for_encryption';
        $storage = new EncryptedStorage($apiKey);

        $originalData = ['flags' => ['feature1' => true, 'feature2' => 'value']];

        $encrypted = $storage->encrypt($originalData);
        $decrypted = $storage->decrypt($encrypted);

        $this->assertEquals($originalData, $decrypted);
    }

    public function testEncryptedStorageProducesDifferentCiphertextEachTime(): void
    {
        $apiKey = 'sdk_test_key';
        $storage = new EncryptedStorage($apiKey);

        $data = ['test' => 'data'];

        $encrypted1 = $storage->encrypt($data);
        $encrypted2 = $storage->encrypt($data);

        // Different IVs should produce different ciphertext
        $this->assertNotEquals($encrypted1, $encrypted2);
    }

    public function testEncryptedStorageWithDifferentKeysCannotDecrypt(): void
    {
        $storage1 = new EncryptedStorage('sdk_key_one');
        $storage2 = new EncryptedStorage('sdk_key_two');

        $data = ['secret' => 'value'];
        $encrypted = $storage1->encrypt($data);

        $this->expectException(SecurityException::class);
        $storage2->decrypt($encrypted);
    }

    public function testEncryptedStorageThrowsOnInvalidBase64(): void
    {
        $storage = new EncryptedStorage('sdk_test_key');

        $this->expectException(SecurityException::class);
        $storage->decrypt('not-valid-base64!!!');
    }

    public function testEncryptedStorageThrowsOnTruncatedData(): void
    {
        $storage = new EncryptedStorage('sdk_test_key');

        // Valid base64 but too short
        $this->expectException(SecurityException::class);
        $storage->decrypt(base64_encode('short'));
    }

    public function testEncryptedStorageCreateWithRandomSalt(): void
    {
        $apiKey = 'sdk_test_key';

        $result = EncryptedStorage::createWithRandomSalt($apiKey);

        $this->assertArrayHasKey('storage', $result);
        $this->assertArrayHasKey('salt', $result);
        $this->assertInstanceOf(EncryptedStorage::class, $result['storage']);
        $this->assertNotEmpty($result['salt']);
    }

    public function testEncryptedStorageFromSalt(): void
    {
        $apiKey = 'sdk_test_key';

        // Create with random salt
        $result = EncryptedStorage::createWithRandomSalt($apiKey);
        $originalStorage = $result['storage'];
        $salt = $result['salt'];

        // Encrypt some data
        $data = ['important' => 'data'];
        $encrypted = $originalStorage->encrypt($data);

        // Recreate storage from same salt
        $recreatedStorage = EncryptedStorage::fromSalt($apiKey, $salt);
        $decrypted = $recreatedStorage->decrypt($encrypted);

        $this->assertEquals($data, $decrypted);
    }

    public function testEncryptedStorageFromSaltThrowsOnInvalidSalt(): void
    {
        $this->expectException(SecurityException::class);
        EncryptedStorage::fromSalt('sdk_key', 'not-valid-base64!!!');
    }

    public function testEncryptedStorageHandlesLargeData(): void
    {
        $storage = new EncryptedStorage('sdk_test_key');

        // Create large data array
        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData["flag_{$i}"] = [
                'enabled' => $i % 2 === 0,
                'value' => str_repeat('x', 100),
                'version' => $i,
            ];
        }

        $encrypted = $storage->encrypt($largeData);
        $decrypted = $storage->decrypt($encrypted);

        $this->assertEquals($largeData, $decrypted);
    }

    public function testEncryptedStorageHandlesSpecialCharacters(): void
    {
        $storage = new EncryptedStorage('sdk_test_key');

        $data = [
            'unicode' => 'Hello \u4e16\u754c',
            'special' => "quotes: \" ' \\ / \n\t\r",
            'emoji' => '123',
        ];

        $encrypted = $storage->encrypt($data);
        $decrypted = $storage->decrypt($encrypted);

        $this->assertEquals($data, $decrypted);
    }
}
