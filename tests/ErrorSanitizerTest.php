<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\Error\ErrorSanitizer;
use FlagKit\Error\FlagKitException;
use FlagKit\Error\ErrorCode;
use PHPUnit\Framework\TestCase;

class ErrorSanitizerTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset sanitization settings before each test
        FlagKitException::configureSanitization(true, false);
        ErrorSanitizer::clearOriginalMessage();
    }

    // ==================== Unix Path Sanitization ====================

    public function testSanitizesUnixPaths(): void
    {
        $message = 'Error reading file /var/www/html/config.php';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Error reading file [PATH]', $sanitized);
    }

    public function testSanitizesDeepUnixPaths(): void
    {
        $message = 'Cannot find /home/user/projects/flagkit/src/Client.php';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Cannot find [PATH]', $sanitized);
    }

    public function testSanitizesMultipleUnixPaths(): void
    {
        $message = 'Copying /src/file.txt to /dest/file.txt failed';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Copying [PATH] to [PATH] failed', $sanitized);
    }

    // ==================== Windows Path Sanitization ====================

    public function testSanitizesWindowsPaths(): void
    {
        $message = 'Error reading file C:\\Users\\Admin\\config.ini';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Error reading file [PATH]', $sanitized);
    }

    public function testSanitizesDeepWindowsPaths(): void
    {
        $message = 'Cannot find D:\\Projects\\FlagKit\\src\\Client.php';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Cannot find [PATH]', $sanitized);
    }

    // ==================== IP Address Sanitization ====================

    public function testSanitizesIPAddresses(): void
    {
        $message = 'Connection refused from 192.168.1.100';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Connection refused from [IP]', $sanitized);
    }

    public function testSanitizesMultipleIPAddresses(): void
    {
        $message = 'Failed to connect from 10.0.0.1 to 172.16.0.1';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Failed to connect from [IP] to [IP]', $sanitized);
    }

    public function testSanitizesLocalhostIP(): void
    {
        $message = 'Server listening on 127.0.0.1:8080';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Server listening on [IP]:8080', $sanitized);
    }

    // ==================== API Key Sanitization ====================

    public function testSanitizesSDKApiKeys(): void
    {
        $message = 'Invalid API key: sdk_abc123def456ghi789';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Invalid API key: sdk_[REDACTED]', $sanitized);
    }

    public function testSanitizesServerApiKeys(): void
    {
        $message = 'Authentication failed for srv_server_key_12345678';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Authentication failed for srv_[REDACTED]', $sanitized);
    }

    public function testSanitizesCLIApiKeys(): void
    {
        $message = 'Using CLI key: cli_command_line_key_90ab';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Using CLI key: cli_[REDACTED]', $sanitized);
    }

    public function testDoesNotSanitizeShortApiKeyPrefixes(): void
    {
        $message = 'Prefix sdk_abc is too short';
        $sanitized = ErrorSanitizer::sanitize($message);

        // Short keys (less than 8 chars after prefix) should not be redacted
        $this->assertEquals('Prefix sdk_abc is too short', $sanitized);
    }

    // ==================== Email Address Sanitization ====================

    public function testSanitizesEmailAddresses(): void
    {
        $message = 'Failed to send to user@example.com';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Failed to send to [EMAIL]', $sanitized);
    }

    public function testSanitizesComplexEmailAddresses(): void
    {
        $message = 'Contact admin.user-123@sub.domain.co.uk for support';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Contact [EMAIL] for support', $sanitized);
    }

    public function testSanitizesMultipleEmails(): void
    {
        $message = 'From: sender@test.com To: receiver@test.org';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('From: [EMAIL] To: [EMAIL]', $sanitized);
    }

    // ==================== Connection String Sanitization ====================

    public function testSanitizesPostgresConnectionStrings(): void
    {
        $message = 'Database error: postgres://user:password@localhost:5432/mydb';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Database error: [CONNECTION_STRING]', $sanitized);
    }

    public function testSanitizesMySQLConnectionStrings(): void
    {
        $message = 'Failed to connect: mysql://root:secret@db.server.com/database';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Failed to connect: [CONNECTION_STRING]', $sanitized);
    }

    public function testSanitizesMongoDBConnectionStrings(): void
    {
        $message = 'MongoDB error: mongodb://admin:pass123@mongo.example.com:27017/admin';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('MongoDB error: [CONNECTION_STRING]', $sanitized);
    }

    public function testSanitizesRedisConnectionStrings(): void
    {
        $message = 'Cache failed: redis://default:password@redis.server.io:6379';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Cache failed: [CONNECTION_STRING]', $sanitized);
    }

    public function testSanitizesConnectionStringsCaseInsensitive(): void
    {
        $message = 'Error: POSTGRES://User:Pass@Host/DB';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('Error: [CONNECTION_STRING]', $sanitized);
    }

    // ==================== Disabled Mode ====================

    public function testDisabledModePreservesOriginalMessage(): void
    {
        $message = 'Error at /var/www/app with key sdk_test12345678';
        $sanitized = ErrorSanitizer::sanitize($message, enabled: false);

        $this->assertEquals($message, $sanitized);
    }

    public function testDisabledModePreservesAllSensitiveInfo(): void
    {
        $message = 'IP: 192.168.1.1, Email: test@example.com, Path: /home/user/file.txt';
        $sanitized = ErrorSanitizer::sanitize($message, enabled: false);

        $this->assertEquals($message, $sanitized);
    }

    // ==================== Multiple Patterns ====================

    public function testSanitizesMultiplePatternsInSingleMessage(): void
    {
        $message = 'User admin@company.com at 10.0.0.50 using sdk_production_key_123 accessed /var/log/app.log';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals('User [EMAIL] at [IP] using sdk_[REDACTED] accessed [PATH]', $sanitized);
    }

    public function testSanitizesAllPatternTypes(): void
    {
        $message = 'Error: Path=/etc/config/db.conf IP=192.168.0.1 Key=srv_abcdefghij Email=user@test.com DB=postgres://u:p@h/d';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertStringContainsString('[PATH]', $sanitized);
        $this->assertStringContainsString('[IP]', $sanitized);
        $this->assertStringContainsString('srv_[REDACTED]', $sanitized);
        $this->assertStringContainsString('[EMAIL]', $sanitized);
        $this->assertStringContainsString('[CONNECTION_STRING]', $sanitized);
    }

    // ==================== Edge Cases ====================

    public function testEmptyMessageReturnsEmpty(): void
    {
        $sanitized = ErrorSanitizer::sanitize('');

        $this->assertEquals('', $sanitized);
    }

    public function testMessageWithoutSensitiveInfoUnchanged(): void
    {
        $message = 'Flag evaluation failed for feature_flag_123';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals($message, $sanitized);
    }

    public function testPreservesNormalNumbers(): void
    {
        $message = 'Error code 12345 at line 67';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals($message, $sanitized);
    }

    public function testPreservesPartialIPLikeNumbers(): void
    {
        $message = 'Version 1.2.3 released';
        $sanitized = ErrorSanitizer::sanitize($message);

        $this->assertEquals($message, $sanitized);
    }

    public function testPreservesUrlsWithoutCredentials(): void
    {
        $message = 'Fetching from https://api.flagkit.dev/v1/flags';
        $sanitized = ErrorSanitizer::sanitize($message);

        // HTTPS URLs without credentials should be preserved
        $this->assertEquals($message, $sanitized);
    }

    // ==================== Sanitization with Preservation ====================

    public function testSanitizeWithPreservationStoresOriginal(): void
    {
        $message = 'Error with sdk_test_key_12345678';

        $sanitized = ErrorSanitizer::sanitizeWithPreservation($message, true, true);

        $this->assertEquals('Error with sdk_[REDACTED]', $sanitized);
        $this->assertEquals($message, ErrorSanitizer::getLastOriginalMessage());
    }

    public function testSanitizeWithPreservationClearsWhenDisabled(): void
    {
        // First store something
        ErrorSanitizer::sanitizeWithPreservation('test sdk_12345678901', true, true);
        $this->assertNotNull(ErrorSanitizer::getLastOriginalMessage());

        // Then sanitize without preservation
        ErrorSanitizer::sanitizeWithPreservation('new message', true, false);
        $this->assertNull(ErrorSanitizer::getLastOriginalMessage());
    }

    public function testClearOriginalMessage(): void
    {
        ErrorSanitizer::sanitizeWithPreservation('test sdk_12345678901', true, true);
        $this->assertNotNull(ErrorSanitizer::getLastOriginalMessage());

        ErrorSanitizer::clearOriginalMessage();
        $this->assertNull(ErrorSanitizer::getLastOriginalMessage());
    }

    // ==================== Contains Sensitive Info ====================

    public function testContainsSensitiveInfoReturnsTrueForPath(): void
    {
        $this->assertTrue(ErrorSanitizer::containsSensitiveInfo('/var/www/file.txt'));
    }

    public function testContainsSensitiveInfoReturnsTrueForIP(): void
    {
        $this->assertTrue(ErrorSanitizer::containsSensitiveInfo('Server at 192.168.1.1'));
    }

    public function testContainsSensitiveInfoReturnsTrueForApiKey(): void
    {
        $this->assertTrue(ErrorSanitizer::containsSensitiveInfo('Key: sdk_abcdefghij'));
    }

    public function testContainsSensitiveInfoReturnsTrueForEmail(): void
    {
        $this->assertTrue(ErrorSanitizer::containsSensitiveInfo('Contact user@example.com'));
    }

    public function testContainsSensitiveInfoReturnsTrueForConnectionString(): void
    {
        $this->assertTrue(ErrorSanitizer::containsSensitiveInfo('postgres://u:p@h/d'));
    }

    public function testContainsSensitiveInfoReturnsFalseForSafeMessage(): void
    {
        $this->assertFalse(ErrorSanitizer::containsSensitiveInfo('Flag evaluation succeeded'));
    }

    // ==================== Get Patterns ====================

    public function testGetPatternsReturnsAllPatterns(): void
    {
        $patterns = ErrorSanitizer::getPatterns();

        $this->assertIsArray($patterns);
        $this->assertNotEmpty($patterns);
        $this->assertArrayHasKey('/sdk_[a-zA-Z0-9_-]{8,}/', $patterns);
        $this->assertArrayHasKey('/srv_[a-zA-Z0-9_-]{8,}/', $patterns);
        $this->assertArrayHasKey('/cli_[a-zA-Z0-9_-]{8,}/', $patterns);
    }

    // ==================== FlagKitException Integration ====================

    public function testFlagKitExceptionSanitizesMessage(): void
    {
        FlagKitException::configureSanitization(true, false);

        $exception = new FlagKitException(
            ErrorCode::NetworkError,
            'Failed to connect to 192.168.1.100 with key sdk_test_api_key_1234'
        );

        $this->assertStringContainsString('[IP]', $exception->getMessage());
        $this->assertStringContainsString('sdk_[REDACTED]', $exception->getMessage());
        $this->assertStringNotContainsString('192.168.1.100', $exception->getMessage());
        $this->assertStringNotContainsString('sdk_test_api_key_1234', $exception->getMessage());
    }

    public function testFlagKitExceptionPreservesOriginalWhenEnabled(): void
    {
        FlagKitException::configureSanitization(true, true);

        $originalMessage = 'Error at /var/www/app.php with sdk_secretkey1234';
        $exception = new FlagKitException(
            ErrorCode::InitFailed,
            $originalMessage
        );

        $this->assertStringContainsString('[PATH]', $exception->getMessage());
        $this->assertStringContainsString('sdk_[REDACTED]', $exception->getMessage());
        $this->assertEquals($originalMessage, $exception->getOriginalMessage());
    }

    public function testFlagKitExceptionDoesNotSanitizeWhenDisabled(): void
    {
        FlagKitException::configureSanitization(false, false);

        $message = 'Error at /var/www/app.php';
        $exception = new FlagKitException(ErrorCode::InitFailed, $message);

        $this->assertStringContainsString('/var/www/app.php', $exception->getMessage());
        $this->assertStringNotContainsString('[PATH]', $exception->getMessage());
    }

    public function testFlagKitExceptionOriginalMessageIsNullWhenNotPreserved(): void
    {
        FlagKitException::configureSanitization(true, false);

        $exception = new FlagKitException(
            ErrorCode::NetworkError,
            'Error message'
        );

        $this->assertNull($exception->getOriginalMessage());
    }

    public function testIsSanitizationEnabled(): void
    {
        FlagKitException::configureSanitization(true, false);
        $this->assertTrue(FlagKitException::isSanitizationEnabled());

        FlagKitException::configureSanitization(false, false);
        $this->assertFalse(FlagKitException::isSanitizationEnabled());
    }

    public function testStaticFactoryMethodsSanitize(): void
    {
        FlagKitException::configureSanitization(true, false);

        $exception = FlagKitException::configError(
            ErrorCode::ConfigInvalidApiKey,
            'Invalid key sdk_my_secret_api_key'
        );

        $this->assertStringContainsString('sdk_[REDACTED]', $exception->getMessage());
    }

    public function testNetworkErrorSanitizes(): void
    {
        FlagKitException::configureSanitization(true, false);

        $exception = FlagKitException::networkError(
            ErrorCode::NetworkError,
            'Connection to 10.0.0.1 failed'
        );

        $this->assertStringContainsString('[IP]', $exception->getMessage());
        $this->assertStringNotContainsString('10.0.0.1', $exception->getMessage());
    }

    public function testEvaluationErrorSanitizes(): void
    {
        FlagKitException::configureSanitization(true, false);

        $exception = FlagKitException::evaluationError(
            ErrorCode::EvalError,
            'Check logs at /var/log/flagkit.log'
        );

        $this->assertStringContainsString('[PATH]', $exception->getMessage());
        $this->assertStringNotContainsString('/var/log/flagkit.log', $exception->getMessage());
    }
}
