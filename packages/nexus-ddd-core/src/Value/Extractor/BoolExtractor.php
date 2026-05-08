<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\BoolValue;
use NoDiscard;

/**
 * @psalm-api
 *
 * Typed extractor for BoolValue subclasses.
 */
final class BoolExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    #[NoDiscard('extract() returns the inner bool — its return is the entire purpose of the call')]
    public static function extract(BoolValue $value): bool
    {
        return ValueExtractor::extract($value);
    }
}
