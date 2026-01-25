<?php

declare(strict_types=1);

namespace FlagKit\Tests;

use FlagKit\FlagKitClient;
use FlagKit\FlagKitOptions;
use FlagKit\Error\SecurityException;
use FlagKit\Types\EvaluationContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for strict PII mode enforcement in FlagKitClient.
 *
 * When strictPIIMode is enabled, the SDK should throw SecurityException
 * when PII is detected in context/event data without privateAttributes.
 */
class StrictPIIModeTest extends TestCase
{
    // ==================== identify() Tests ====================

    public function testIdentifyWithPIIInStrictModeThrowsException(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false) // Disable events to avoid HTTP calls
            ->build();

        $client = new FlagKitClient($options);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('PII detected');

        $client->identify('user-123', [
            'email' => 'user@example.com',
            'plan' => 'premium',
        ]);
    }

    public function testIdentifyWithMultiplePIIFieldsInStrictModeThrowsException(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('email');

        $client->identify('user-123', [
            'email' => 'user@example.com',
            'phone' => '555-1234',
            'ssn' => '123-45-6789',
        ]);
    }

    public function testIdentifyWithPIIInNonStrictModeDoesNotThrow(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(false)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        // Should not throw, just log warning
        $exception = null;
        try {
            $client->identify('user-123', [
                'email' => 'user@example.com',
            ]);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testIdentifyWithPrivateAttributesDoesNotThrowInStrictMode(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        // Should not throw because privateAttributes is set
        $exception = null;
        try {
            $client->identify('user-123', [
                'email' => 'user@example.com',
                'privateAttributes' => ['email'],
            ]);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testIdentifyWithSafeDataDoesNotThrowInStrictMode(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        // Should not throw because data is safe
        $exception = null;
        try {
            $client->identify('user-123', [
                'plan' => 'premium',
                'country' => 'US',
            ]);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testIdentifyWithNullAttributesDoesNotThrowInStrictMode(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        // Should not throw because attributes are null
        $exception = null;
        try {
            $client->identify('user-123');
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    // ==================== setContext() Tests ====================

    public function testSetContextWithPIIInStrictModeThrowsException(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        $context = EvaluationContext::builder()
            ->userId('user-123')
            ->attribute('email', 'user@example.com')
            ->build();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('PII detected');

        $client->setContext($context);
    }

    public function testSetContextWithPIIInNonStrictModeDoesNotThrow(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(false)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        $context = EvaluationContext::builder()
            ->userId('user-123')
            ->attribute('email', 'user@example.com')
            ->build();

        // Should not throw, just log warning
        $exception = null;
        try {
            $client->setContext($context);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testSetContextWithPrivateAttributePrefixDoesNotThrowInStrictMode(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        // Using _ prefix marks attribute as private
        $context = EvaluationContext::builder()
            ->userId('user-123')
            ->attribute('_email', 'user@example.com')
            ->build();

        // Should not throw because attribute has private prefix
        $exception = null;
        try {
            $client->setContext($context);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testSetContextWithSafeDataDoesNotThrowInStrictMode(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        $context = EvaluationContext::builder()
            ->userId('user-123')
            ->attribute('plan', 'premium')
            ->attribute('country', 'US')
            ->build();

        // Should not throw because data is safe
        $exception = null;
        try {
            $client->setContext($context);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    // ==================== track() Tests ====================

    public function testTrackWithPIIInStrictModeThrowsException(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('PII detected');

        $client->track('purchase', [
            'amount' => 99.99,
            'email' => 'user@example.com',
        ]);
    }

    public function testTrackWithNestedPIIInStrictModeThrowsException(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        $this->expectException(SecurityException::class);

        $client->track('purchase', [
            'amount' => 99.99,
            'customer' => [
                'email' => 'user@example.com',
                'phone' => '555-1234',
            ],
        ]);
    }

    public function testTrackWithPIIInNonStrictModeDoesNotThrow(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(false)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        // Should not throw, just log warning
        $exception = null;
        try {
            $client->track('purchase', [
                'amount' => 99.99,
                'email' => 'user@example.com',
            ]);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testTrackWithSafeDataDoesNotThrowInStrictMode(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        // Should not throw because data is safe
        $exception = null;
        try {
            $client->track('purchase', [
                'amount' => 99.99,
                'productId' => 'prod-123',
            ]);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testTrackWithNullDataDoesNotThrowInStrictMode(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        // Should not throw because data is null
        $exception = null;
        try {
            $client->track('page_view');
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    // ==================== Edge Cases ====================

    public function testStrictModeDefaultsToFalse(): void
    {
        $options = new FlagKitOptions(apiKey: 'sdk_test123', eventsEnabled: false);
        $client = new FlagKitClient($options);

        // Should not throw because strict mode is disabled by default
        $exception = null;
        try {
            $client->identify('user-123', [
                'email' => 'user@example.com',
            ]);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception);
    }

    public function testSecurityExceptionContainsFieldNames(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        try {
            $client->identify('user-123', [
                'email' => 'user@example.com',
                'phone' => '555-1234',
            ]);
            $this->fail('Expected SecurityException was not thrown');
        } catch (SecurityException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('email', $message);
            $this->assertStringContainsString('phone', $message);
        }
    }

    public function testPIIDetectionIsCaseInsensitive(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        $client = new FlagKitClient($options);

        $this->expectException(SecurityException::class);

        $client->identify('user-123', [
            'userEmail' => 'user@example.com',
        ]);
    }

    public function testPIIDetectionForVariousFieldTypes(): void
    {
        $options = FlagKitOptions::builder('sdk_test123')
            ->strictPIIMode(true)
            ->eventsEnabled(false)
            ->build();

        // Test various PII field names
        $piiFieldNames = [
            'email',
            'phoneNumber',
            'ssn',
            'creditCard',
            'password',
            'apiKey',
            'address',
            'dateOfBirth',
            'passport',
            'bankAccount',
        ];

        foreach ($piiFieldNames as $fieldName) {
            $client = new FlagKitClient($options);

            try {
                $client->track('test', [$fieldName => 'test-value']);
                $this->fail("Expected SecurityException for field: {$fieldName}");
            } catch (SecurityException $e) {
                $this->assertStringContainsString('PII detected', $e->getMessage());
            }
        }
    }
}
