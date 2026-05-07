<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\IntValue;

/**
 * @psalm-api
 *
 * Typed extractor for IntValue subclasses.
 */
final class IntExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    public static function extract(IntValue $value): int
    {
        return ValueExtractor::extract($value);
    }
}
