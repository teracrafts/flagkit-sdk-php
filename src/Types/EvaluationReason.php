<?php

declare(strict_types=1);

namespace FlagKit\Types;

/**
 * Evaluation reason codes as per SDK specification.
 */
enum EvaluationReason: string
{
    /** Flag value retrieved from cache */
    case Cached = 'cached';

    /** Default value was returned */
    case Default = 'default';

    /** Flag was not found */
    case FlagNotFound = 'FLAG_NOT_FOUND';

    /** Flag is disabled */
    case FlagDisabled = 'FLAG_DISABLED';

    /** Environment not configured */
    case EnvironmentNotConfigured = 'ENVIRONMENT_NOT_CONFIGURED';

    /** Default targeting rule matched */
    case Fallthrough = 'FALLTHROUGH';

    /** A targeting rule matched */
    case RuleMatch = 'RULE_MATCH';

    /** User is in a matched segment */
    case SegmentMatch = 'SEGMENT_MATCH';

    /** Evaluation error occurred */
    case EvaluationError = 'EVALUATION_ERROR';

    // Legacy/internal reasons
    case Bootstrap = 'bootstrap';
    case Server = 'server';
    case StaleCache = 'stale_cache';
    case Stale = 'stale';
    case Error = 'error';
    case Disabled = 'disabled';
    case TypeMismatch = 'type_mismatch';
    case Offline = 'offline';

    /**
     * Create from a string value, with fallback to Default.
     */
    public static function from(string $value): self
    {
        return match (strtoupper($value)) {
            'FLAG_NOT_FOUND' => self::FlagNotFound,
            'FLAG_DISABLED' => self::FlagDisabled,
            'ENVIRONMENT_NOT_CONFIGURED' => self::EnvironmentNotConfigured,
            'FALLTHROUGH' => self::Fallthrough,
            'RULE_MATCH' => self::RuleMatch,
            'SEGMENT_MATCH' => self::SegmentMatch,
            'DEFAULT' => self::Default,
            'EVALUATION_ERROR' => self::EvaluationError,
            'CACHED' => self::Cached,
            'BOOTSTRAP' => self::Bootstrap,
            'SERVER' => self::Server,
            'STALE_CACHE' => self::StaleCache,
            'STALE' => self::Stale,
            'ERROR' => self::Error,
            'DISABLED' => self::Disabled,
            'TYPE_MISMATCH' => self::TypeMismatch,
            'OFFLINE' => self::Offline,
            default => self::Default,
        };
    }

    /**
     * Check if this reason indicates the flag was found and evaluated.
     */
    public function isSuccess(): bool
    {
        return match ($this) {
            self::Cached,
            self::Fallthrough,
            self::RuleMatch,
            self::SegmentMatch,
            self::Bootstrap,
            self::Server => true,
            default => false,
        };
    }

    /**
     * Check if this reason indicates an error or issue.
     */
    public function isError(): bool
    {
        return match ($this) {
            self::FlagNotFound,
            self::FlagDisabled,
            self::EnvironmentNotConfigured,
            self::EvaluationError,
            self::Error,
            self::TypeMismatch => true,
            default => false,
        };
    }

    /**
     * Check if this reason indicates a stale or fallback value.
     */
    public function isStale(): bool
    {
        return match ($this) {
            self::StaleCache,
            self::Stale,
            self::Offline => true,
            default => false,
        };
    }
}
