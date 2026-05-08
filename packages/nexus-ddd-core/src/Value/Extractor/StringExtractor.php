<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\StringValue;
use NoDiscard;

/**
 * @psalm-api
 *
 * Typed extractor for StringValue subclasses.
 */
final class StringExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    #[NoDiscard('extract() returns the inner string — its return is the entire purpose of the call')]
    public static function extract(StringValue $value): string
    {
        return ValueExtractor::extract($value);
    }
}
