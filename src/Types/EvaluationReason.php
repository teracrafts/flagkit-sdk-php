<?php

declare(strict_types=1);

namespace FlagKit\Types;

enum EvaluationReason: string
{
    case Cached = 'cached';
    case Default = 'default';
    case FlagNotFound = 'flag_not_found';
    case Bootstrap = 'bootstrap';
    case Server = 'server';
    case StaleCache = 'stale_cache';
    case Error = 'error';
    case Disabled = 'disabled';
    case TypeMismatch = 'type_mismatch';
    case Offline = 'offline';
}
