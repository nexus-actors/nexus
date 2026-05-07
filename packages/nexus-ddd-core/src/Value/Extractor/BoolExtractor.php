<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\BoolValue;

/**
 * @psalm-api
 *
 * Typed extractor for BoolValue subclasses.
 */
final class BoolExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    public static function extract(BoolValue $value): bool
    {
        return ValueExtractor::extract($value);
    }
}
