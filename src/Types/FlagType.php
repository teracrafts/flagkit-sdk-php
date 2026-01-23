<?php

declare(strict_types=1);

namespace FlagKit\Types;

enum FlagType: string
{
    case Boolean = 'boolean';
    case String = 'string';
    case Number = 'number';
    case Json = 'json';
}
