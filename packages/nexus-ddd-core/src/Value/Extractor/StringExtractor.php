<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\StringValue;

/**
 * @psalm-api
 *
 * Typed extractor for StringValue subclasses.
 */
final class StringExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    public static function extract(StringValue $value): string
    {
        return ValueExtractor::extract($value);
    }
}
