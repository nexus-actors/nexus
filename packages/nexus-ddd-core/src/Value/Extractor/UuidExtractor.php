<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\UuidValue;

/**
 * @psalm-api
 *
 * Typed extractor for UuidValue. Returns the canonical 36-character UUID string.
 */
final class UuidExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    public static function extract(UuidValue $value): string
    {
        return ValueExtractor::extract($value);
    }
}
