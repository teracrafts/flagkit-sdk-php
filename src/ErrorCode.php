<?php

declare(strict_types=1);

namespace FlagKit;

enum ErrorCode: string
{
    // Initialization errors
    case InitFailed = 'INIT_FAILED';
    case InitTimeout = 'INIT_TIMEOUT';
    case InitAlreadyInitialized = 'INIT_ALREADY_INITIALIZED';
    case InitNotInitialized = 'INIT_NOT_INITIALIZED';

    // Authentication errors
    case AuthInvalidKey = 'AUTH_INVALID_KEY';
    case AuthExpiredKey = 'AUTH_EXPIRED_KEY';
    case AuthMissingKey = 'AUTH_MISSING_KEY';
    case AuthUnauthorized = 'AUTH_UNAUTHORIZED';
    case AuthPermissionDenied = 'AUTH_PERMISSION_DENIED';

    // Network errors
    case NetworkError = 'NETWORK_ERROR';
    case NetworkTimeout = 'NETWORK_TIMEOUT';
    case NetworkRetryLimit = 'NETWORK_RETRY_LIMIT';

    // HTTP errors
    case HttpBadRequest = 'HTTP_BAD_REQUEST';
    case HttpUnauthorized = 'HTTP_UNAUTHORIZED';
    case HttpForbidden = 'HTTP_FORBIDDEN';
    case HttpNotFound = 'HTTP_NOT_FOUND';
    case HttpRateLimited = 'HTTP_RATE_LIMITED';
    case HttpServerError = 'HTTP_SERVER_ERROR';
    case HttpTimeout = 'HTTP_TIMEOUT';
    case HttpNetworkError = 'HTTP_NETWORK_ERROR';
    case HttpInvalidResponse = 'HTTP_INVALID_RESPONSE';
    case HttpCircuitOpen = 'HTTP_CIRCUIT_OPEN';

    // Evaluation errors
    case EvalFlagNotFound = 'EVAL_FLAG_NOT_FOUND';
    case EvalTypeMismatch = 'EVAL_TYPE_MISMATCH';
    case EvalInvalidKey = 'EVAL_INVALID_KEY';
    case EvalInvalidValue = 'EVAL_INVALID_VALUE';
    case EvalDisabled = 'EVAL_DISABLED';
    case EvalError = 'EVAL_ERROR';
    case EvalContextError = 'EVAL_CONTEXT_ERROR';
    case EvalDefaultUsed = 'EVAL_DEFAULT_USED';
    case EvalStaleValue = 'EVAL_STALE_VALUE';
    case EvalCacheMiss = 'EVAL_CACHE_MISS';
    case EvalNetworkError = 'EVAL_NETWORK_ERROR';
    case EvalParseError = 'EVAL_PARSE_ERROR';
    case EvalTimeoutError = 'EVAL_TIMEOUT_ERROR';

    // Cache errors
    case CacheReadError = 'CACHE_READ_ERROR';
    case CacheWriteError = 'CACHE_WRITE_ERROR';
    case CacheInvalidData = 'CACHE_INVALID_DATA';
    case CacheExpired = 'CACHE_EXPIRED';
    case CacheStorageError = 'CACHE_STORAGE_ERROR';

    // Event errors
    case EventQueueFull = 'EVENT_QUEUE_FULL';
    case EventInvalidType = 'EVENT_INVALID_TYPE';
    case EventInvalidData = 'EVENT_INVALID_DATA';
    case EventSendFailed = 'EVENT_SEND_FAILED';
    case EventFlushFailed = 'EVENT_FLUSH_FAILED';
    case EventFlushTimeout = 'EVENT_FLUSH_TIMEOUT';

    // Circuit breaker errors
    case CircuitOpen = 'CIRCUIT_OPEN';

    // SDK lifecycle errors
    case SdkNotInitialized = 'SDK_NOT_INITIALIZED';
    case SdkAlreadyInitialized = 'SDK_ALREADY_INITIALIZED';
    case SdkNotReady = 'SDK_NOT_READY';

    // Configuration errors
    case ConfigInvalidUrl = 'CONFIG_INVALID_URL';
    case ConfigInvalidInterval = 'CONFIG_INVALID_INTERVAL';
    case ConfigMissingRequired = 'CONFIG_MISSING_REQUIRED';
    case ConfigInvalidApiKey = 'CONFIG_INVALID_API_KEY';
    case ConfigInvalidBaseUrl = 'CONFIG_INVALID_BASE_URL';
    case ConfigInvalidPollingInterval = 'CONFIG_INVALID_POLLING_INTERVAL';
    case ConfigInvalidCacheTtl = 'CONFIG_INVALID_CACHE_TTL';

    private const RECOVERABLE_CODES = [
        self::NetworkError,
        self::NetworkTimeout,
        self::NetworkRetryLimit,
        self::CircuitOpen,
        self::HttpCircuitOpen,
        self::HttpTimeout,
        self::HttpNetworkError,
        self::HttpServerError,
        self::HttpRateLimited,
        self::CacheExpired,
        self::EvalStaleValue,
        self::EvalCacheMiss,
        self::EvalNetworkError,
        self::EventSendFailed,
    ];

    public function isRecoverable(): bool
    {
        return in_array($this, self::RECOVERABLE_CODES, true);
    }
}
