# FlagKit PHP SDK

Official PHP SDK for [FlagKit](https://flagkit.dev) feature flag service.

## Requirements

- PHP 8.1 or later
- Composer

## Installation

```bash
composer require teracrafts/flagkit
```

## Features

- **Type-safe evaluation** - Boolean, string, number, and JSON flag types
- **Local caching** - Fast evaluations with configurable TTL and optional encryption
- **Background polling** - Automatic flag updates
- **Event tracking** - Analytics with batching and crash-resilient persistence
- **Resilient** - Circuit breaker, retry with exponential backoff, offline support
- **Security** - PII detection, request signing, bootstrap verification, timing attack protection

## Architecture

The SDK is organized into clean, modular components:

```
FlagKit/
├── FlagKit.php              # Static factory methods
├── FlagKitClient.php        # Main client implementation
├── FlagKitOptions.php       # Configuration options
├── Core/                    # Core components
│   ├── FlagCache.php        # In-memory cache with TTL
│   ├── ContextManager.php
│   ├── PollingManager.php
│   ├── EventQueue.php       # Event batching
│   └── EventPersistence.php # Crash-resilient persistence
├── Http/                    # HTTP client, circuit breaker, retry
│   ├── HttpClient.php
│   └── CircuitBreaker.php
├── Error/                   # Error types and codes
│   ├── FlagKitException.php
│   ├── ErrorCode.php
│   └── ErrorSanitizer.php
├── Types/                   # Type definitions
│   ├── EvaluationContext.php
│   ├── EvaluationResult.php
│   └── FlagState.php
└── Utils/                   # Utilities
    └── Security.php         # PII detection, HMAC signing
```

## Quick Start

```php
<?php

use FlagKit\FlagKit;
use FlagKit\FlagKitOptions;

// Initialize the SDK
$options = new FlagKitOptions(apiKey: 'sdk_your_api_key');
$client = FlagKit::initializeAndStart($options);

// Identify user
FlagKit::identify('user-123', [
    'plan' => 'premium',
    'beta' => true,
]);

// Evaluate flags
$darkMode = FlagKit::getBooleanValue('dark-mode', defaultValue: false);
$theme = FlagKit::getStringValue('theme', defaultValue: 'light');
$maxItems = FlagKit::getIntValue('max-items', defaultValue: 10);

// Track events
FlagKit::track('button_clicked', ['button' => 'signup']);

// Cleanup when done
FlagKit::close();
```

## Configuration

```php
<?php

use FlagKit\FlagKitOptions;

$options = FlagKitOptions::builder('sdk_your_api_key')
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

$client = FlagKit::initialize($options);
$client->initialize();
```

Or using direct construction:

```php
$options = new FlagKitOptions(
    apiKey: 'sdk_your_api_key',
    pollingInterval: 60,
    cacheTtl: 600,
    maxCacheSize: 500,
);
```

## Evaluation Context

Provide context for targeting rules:

```php
<?php

use FlagKit\EvaluationContext;
use FlagKit\FlagKit;

// Using builder pattern
$context = EvaluationContext::builder()
    ->userId('user-123')
    ->attribute('plan', 'premium')
    ->attribute('beta', true)
    ->attribute('score', 95.5)
    ->build();

$result = FlagKit::evaluate('feature-flag', $context);

// Using fluent methods
$context = (new EvaluationContext())
    ->withUserId('user-123')
    ->withAttribute('plan', 'premium')
    ->withAttributes([
        'region' => 'us-east',
        'beta' => true,
    ]);
```

## Flag Evaluation

### Basic Evaluation

```php
// Boolean flags
$enabled = FlagKit::getBooleanValue('feature-enabled', defaultValue: false);

// String flags
$variant = FlagKit::getStringValue('experiment-variant', defaultValue: 'control');

// Number flags
$limit = FlagKit::getNumberValue('rate-limit', defaultValue: 100.0);
$count = FlagKit::getIntValue('max-count', defaultValue: 10);

// JSON flags
$config = FlagKit::getJsonValue('feature-config', defaultValue: null);
```

### Detailed Evaluation

```php
$result = FlagKit::evaluate('feature-flag');

echo "Flag: " . $result->flagKey . "\n";
echo "Value: " . print_r($result->value->getRaw(), true) . "\n";
echo "Enabled: " . ($result->enabled ? 'true' : 'false') . "\n";
echo "Reason: " . $result->reason->value . "\n";
echo "Version: " . $result->version . "\n";
```

## User Identification

```php
// Identify user with attributes
FlagKit::identify('user-123', [
    'email' => 'user@example.com',
    'plan' => 'enterprise',
    'created_at' => date('c'),
]);

// Update context
FlagKit::setContext(
    (new EvaluationContext())
        ->withUserId('user-456')
        ->withAttribute('admin', true)
);

// Clear context
FlagKit::clearContext();
```

## Analytics

```php
// Track custom events
FlagKit::track('purchase_completed', [
    'amount' => 99.99,
    'currency' => 'USD',
    'product_id' => 'prod-123',
]);

// Flush pending events
FlagKit::flush();
```

## Bootstrap Data

Initialize with local flag data for instant evaluation:

```php
$options = FlagKitOptions::builder('sdk_your_api_key')
    ->bootstrap([
        'dark-mode' => true,
        'theme' => 'dark',
        'max-items' => 50,
    ])
    ->build();

$client = FlagKit::initialize($options);
// Flags available immediately from bootstrap
```

## Error Handling

```php
<?php

use FlagKit\FlagKitException;
use FlagKit\ErrorCode;

try {
    $client->initialize();
} catch (FlagKitException $e) {
    if ($e->isConfigError()) {
        echo "Configuration error: " . $e->getMessage() . "\n";
    } elseif ($e->isNetworkError()) {
        echo "Network error: " . $e->getMessage() . "\n";
    } else {
        echo "Error [{$e->getErrorCode()->value}]: " . $e->getMessage() . "\n";
    }
}
```

## API Reference

### FlagKit (Static Factory)

| Method | Description |
|--------|-------------|
| `initialize($options)` | Initialize SDK with options |
| `initializeAndStart($options)` | Initialize and start SDK |
| `close()` | Close SDK and release resources |
| `identify($userId, $attributes)` | Set user context |
| `setContext($context)` | Set evaluation context |
| `clearContext()` | Clear evaluation context |
| `evaluate($flagKey, $context)` | Evaluate a flag |
| `getBooleanValue(...)` | Get boolean flag value |
| `getStringValue(...)` | Get string flag value |
| `getNumberValue(...)` | Get number flag value |
| `getIntValue(...)` | Get integer flag value |
| `getJsonValue(...)` | Get JSON flag value |
| `getAllFlags()` | Get all cached flags |
| `track($eventType, $data)` | Track custom event |
| `flush()` | Flush pending events |

### FlagKitOptions

| Property | Default | Description |
|----------|---------|-------------|
| `apiKey` | (required) | API key for authentication |
| `pollingInterval` | 30 | Polling interval (seconds) |
| `cacheTtl` | 300 | Cache time-to-live (seconds) |
| `maxCacheSize` | 1000 | Maximum cache entries |
| `cacheEnabled` | true | Enable caching |
| `eventBatchSize` | 10 | Events per batch |
| `eventFlushInterval` | 30 | Event flush interval (seconds) |
| `eventsEnabled` | true | Enable event tracking |
| `timeout` | 10 | HTTP timeout (seconds) |
| `retryAttempts` | 3 | Max retry attempts |
| `bootstrap` | null | Initial flag data |
| `localPort` | null | Local dev server port (uses `http://localhost:{port}/api/v1`) |

## Local Development

For local development, use the `localPort` option to connect to a local FlagKit server:

```php
$options = FlagKitOptions::builder('sdk_your_api_key')
    ->localPort(8200)  // Uses http://localhost:8200/api/v1
    ->build();

$client = FlagKit::initialize($options);
```

## Security Features

### PII Detection

The SDK can detect and warn about potential PII (Personally Identifiable Information) in contexts and events:

```php
<?php

use FlagKit\FlagKitOptions;
use FlagKit\Error\SecurityException;

// Enable strict PII mode - throws exceptions instead of warnings
$options = FlagKitOptions::builder('sdk_...')
    ->strictPIIMode(true)
    ->build();

// Attributes containing PII will throw SecurityException
try {
    FlagKit::identify('user-123', [
        'email' => 'user@example.com'  // PII detected!
    ]);
} catch (SecurityException $e) {
    echo "PII error: " . $e->getMessage() . "\n";
}
```

### Request Signing

POST requests to the FlagKit API are signed with HMAC-SHA256 for integrity (enabled by default):

```php
$options = FlagKitOptions::builder('sdk_...')
    ->enableRequestSigning(false)  // Disable if needed
    ->build();
```

### Bootstrap Signature Verification

Verify bootstrap data integrity using HMAC signatures:

```php
use FlagKit\Utils\Security;

// Create signed bootstrap data
$bootstrap = Security::createBootstrapSignature(
    flags: ['feature-a' => true, 'feature-b' => 'value'],
    apiKey: 'sdk_your_api_key'
);

// Use signed bootstrap with verification
$options = FlagKitOptions::builder('sdk_...')
    ->bootstrap($bootstrap)
    ->bootstrapVerificationEnabled(true)
    ->bootstrapVerificationMaxAge(86400000)  // 24 hours in milliseconds
    ->bootstrapVerificationOnFailure('error')  // 'warn' (default), 'error', or 'ignore'
    ->build();
```

### Cache Encryption

Enable encryption for cached flag data:

```php
$options = FlagKitOptions::builder('sdk_...')
    ->enableCacheEncryption(true)
    ->build();
```

### Evaluation Jitter (Timing Attack Protection)

Add random delays to flag evaluations to prevent cache timing attacks:

```php
$options = FlagKitOptions::builder('sdk_...')
    ->evaluationJitterEnabled(true)
    ->evaluationJitterMinMs(5)
    ->evaluationJitterMaxMs(15)
    ->build();
```

### Error Sanitization

Automatically redact sensitive information from error messages (enabled by default):

```php
$options = FlagKitOptions::builder('sdk_...')
    ->errorSanitizationEnabled(true)
    ->errorSanitizationPreserveOriginal(false)  // Set true for debugging
    ->build();
// Errors will have paths, IPs, API keys, and emails redacted
```

## Event Persistence

Enable crash-resilient event persistence to prevent data loss:

```php
$options = FlagKitOptions::builder('sdk_...')
    ->persistEvents(true)
    ->eventStoragePath('/path/to/storage')  // Optional, defaults to temp dir
    ->maxPersistedEvents(10000)             // Optional, default 10000
    ->persistenceFlushInterval(1000)        // Optional, default 1000ms
    ->build();
```

Events are written to disk before being queued for sending, and automatically recovered on restart.

## Key Rotation

Support seamless API key rotation:

```php
$options = FlagKitOptions::builder('sdk_primary_key')
    ->secondaryApiKey('sdk_secondary_key')
    ->keyRotationGracePeriod(300)  // 5 minutes (seconds)
    ->build();
// SDK will automatically failover to secondary key on 401 errors
```

## All Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `apiKey` | string | Required | API key for authentication |
| `secondaryApiKey` | string? | null | Secondary key for rotation |
| `keyRotationGracePeriod` | int | 300 | Grace period in seconds |
| `pollingInterval` | int | 30 | Polling interval (seconds) |
| `cacheTtl` | int | 300 | Cache TTL (seconds) |
| `maxCacheSize` | int | 1000 | Maximum cache entries |
| `cacheEnabled` | bool | true | Enable local caching |
| `enableCacheEncryption` | bool | false | Enable cache encryption |
| `eventsEnabled` | bool | true | Enable event tracking |
| `eventBatchSize` | int | 10 | Events per batch |
| `eventFlushInterval` | int | 30 | Interval between flushes (seconds) |
| `timeout` | int | 10 | Request timeout (seconds) |
| `retryAttempts` | int | 3 | Number of retry attempts |
| `circuitBreakerThreshold` | int | 5 | Failures before circuit opens |
| `circuitBreakerResetTimeout` | int | 30 | Time before half-open (seconds) |
| `bootstrap` | array? | null | Initial flag values |
| `localPort` | int? | null | Local development port |
| `strictPIIMode` | bool | false | Error on PII detection |
| `enableRequestSigning` | bool | true | Enable request signing |
| `persistEvents` | bool | false | Enable event persistence |
| `eventStoragePath` | string? | temp dir | Event storage directory |
| `maxPersistedEvents` | int | 10000 | Max persisted events |
| `persistenceFlushInterval` | int | 1000 | Persistence flush interval (ms) |
| `evaluationJitterEnabled` | bool | false | Enable timing attack protection |
| `evaluationJitterMinMs` | int | 5 | Minimum jitter delay (ms) |
| `evaluationJitterMaxMs` | int | 15 | Maximum jitter delay (ms) |
| `bootstrapVerificationEnabled` | bool | true | Verify bootstrap signatures |
| `bootstrapVerificationMaxAge` | int | 86400000 | Max age of bootstrap (ms) |
| `bootstrapVerificationOnFailure` | string | 'warn' | 'warn', 'error', or 'ignore' |
| `errorSanitizationEnabled` | bool | true | Sanitize error messages |
| `errorSanitizationPreserveOriginal` | bool | false | Keep original errors for debugging |

## Development

```bash
# Install dependencies
composer install

# Run tests
composer test

# Run linting
composer lint

# Run static analysis
composer analyse
```

## License

MIT License - see LICENSE file for details.
