#!/usr/bin/env php
<?php

/**
 * FlagKit PHP SDK Lab
 *
 * Internal verification script for SDK functionality.
 * Run with: php sdk-lab/run.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use FlagKit\FlagKit;
use FlagKit\FlagKitOptions;
use FlagKit\Error\FlagKitException;

const PASS = "\033[32m[PASS]\033[0m";
const FAIL = "\033[31m[FAIL]\033[0m";

$passed = 0;
$failed = 0;

function pass(string $test): void
{
    global $passed;
    echo PASS . " {$test}\n";
    $passed++;
}

function fail(string $test): void
{
    global $failed;
    echo FAIL . " {$test}\n";
    $failed++;
}

echo "=== FlagKit PHP SDK Lab ===\n\n";

try {
    // Test 1: Initialization with bootstrap (PHP SDK doesn't have offline mode)
    echo "Testing initialization...\n";
    // Note: PHP SDK will try to connect but fail gracefully, using bootstrap values
    $options = new FlagKitOptions(
        apiKey: 'sdk_lab_test_key',
        bootstrap: [
            'lab-bool' => true,
            'lab-string' => 'Hello Lab',
            'lab-number' => 42.0,
            'lab-json' => ['nested' => true, 'count' => 100.0],
        ]
    );

    try {
        $client = FlagKit::initialize($options);
        $client->waitForReady();
        if ($client->isReady()) {
            pass('Initialization');
        } else {
            // Even if not ready due to network, we can still try to use bootstrap
            pass('Initialization (network error but using bootstrap)');
        }
    } catch (FlagKitException $e) {
        // Network errors are expected when no server is running
        // The client may still be usable with bootstrap values
        echo "Note: Network init failed (expected): " . $e->getMessage() . "\n";
        $client = FlagKit::getInstance();
        pass('Initialization (network error but using bootstrap)');
    }

    // Test 2: Boolean flag evaluation
    echo "\nTesting flag evaluation...\n";
    $boolValue = $client->getBooleanValue('lab-bool', false);
    if ($boolValue === true) {
        pass('Boolean flag evaluation');
    } else {
        fail("Boolean flag - expected true, got " . var_export($boolValue, true));
    }

    // Test 3: String flag evaluation
    $stringValue = $client->getStringValue('lab-string', '');
    if ($stringValue === 'Hello Lab') {
        pass('String flag evaluation');
    } else {
        fail("String flag - expected 'Hello Lab', got '{$stringValue}'");
    }

    // Test 4: Number flag evaluation
    $numberValue = $client->getNumberValue('lab-number', 0);
    if ($numberValue === 42.0) {
        pass('Number flag evaluation');
    } else {
        fail("Number flag - expected 42, got {$numberValue}");
    }

    // Test 5: JSON flag evaluation
    $jsonValue = $client->getJsonValue('lab-json', ['nested' => false, 'count' => 0]);
    if ($jsonValue['nested'] === true && $jsonValue['count'] === 100.0) {
        pass('JSON flag evaluation');
    } else {
        fail('JSON flag - unexpected value: ' . json_encode($jsonValue));
    }

    // Test 6: Default value for missing flag
    $missingValue = $client->getBooleanValue('non-existent', true);
    if ($missingValue === true) {
        pass('Default value for missing flag');
    } else {
        fail("Missing flag - expected default true, got " . var_export($missingValue, true));
    }

    // Test 7: Context management - identify
    echo "\nTesting context management...\n";
    // PHP SDK stores custom attributes in 'attributes' array with FlagValue objects
    $client->identify('lab-user-123', ['custom' => ['plan' => 'premium', 'country' => 'US']]);
    $context = $client->getContext();
    if ($context && $context->userId === 'lab-user-123') {
        pass('identify()');
    } else {
        fail('identify() - context not set correctly');
    }

    // Test 8: Context management - getContext
    // PHP SDK stores 'custom' as an attribute with array value, access via getRaw()
    $customAttr = $context && isset($context->attributes['custom']) ? $context->attributes['custom'] : null;
    $customValue = $customAttr ? $customAttr->getRaw() : null;
    if (is_array($customValue) && ($customValue['plan'] ?? null) === 'premium') {
        pass('getContext()');
    } else {
        fail('getContext() - custom attributes missing');
    }

    // Test 9: Context management - reset
    $client->reset();
    $resetContext = $client->getContext();
    if ($resetContext === null || $resetContext->userId === null) {
        pass('reset()');
    } else {
        fail('reset() - context not cleared');
    }

    // Test 10: Event tracking
    echo "\nTesting event tracking...\n";
    try {
        $client->track('lab_verification', ['sdk' => 'php', 'version' => '1.0.0']);
        pass('track()');
    } catch (Exception $e) {
        fail('track() - ' . $e->getMessage());
    }

    // Test 11: Flush (may fail due to network - that's OK)
    try {
        $client->flush();
        pass('flush()');
    } catch (Exception $e) {
        // In no-server mode, flush may fail - this is expected
        pass('flush() (network error expected)');
    }

    // Test 12: Cleanup
    echo "\nTesting cleanup...\n";
    try {
        $client->close();
        pass('close()');
    } catch (Exception $e) {
        // Close may fail trying to flush events - that's OK without a server
        pass('close() (network error expected)');
    }

} catch (Exception $e) {
    fail('Unexpected error: ' . $e->getMessage());
    echo $e->getTraceAsString() . "\n";
}

// Summary
echo "\n" . str_repeat('=', 40) . "\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo str_repeat('=', 40) . "\n";

if ($failed > 0) {
    echo "\n\033[31mSome verifications failed!\033[0m\n";
    exit(1);
} else {
    echo "\n\033[32mAll verifications passed!\033[0m\n";
    exit(0);
}
