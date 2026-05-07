<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\FloatValue;

/**
 * @psalm-api
 *
 * Typed extractor for FloatValue subclasses.
 */
final class FloatExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    public static function extract(FloatValue $value): float
    {
        return ValueExtractor::extract($value);
    }
}
