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

The SDK also supports security-related configuration options such as PII detection, request signing, cache encryption, bootstrap signature verification, evaluation jitter, and error sanitization. These can be enabled through their respective builder methods or constructor parameters.

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

## License

MIT License - see LICENSE file for details.
