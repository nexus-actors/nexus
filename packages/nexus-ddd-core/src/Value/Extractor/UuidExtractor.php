<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use NoDiscard;

/**
 * @psalm-api
 *
 * Typed extractor for UuidValue. Returns the canonical 36-character UUID string.
 */
final class UuidExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    #[NoDiscard('extract() returns the canonical UUID string — its return is the entire purpose of the call')]
    public static function extract(UuidValue $value): string
    {
        return ValueExtractor::extract($value);
    }
}
