<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\UlidValue;

/**
 * @psalm-api
 *
 * Typed extractor for UlidValue. Returns the canonical 26-character ULID string.
 *
 * `UlidValue::value()` is also publicly available (Identifier contract), but
 * this extractor exists for consistency with other typed extractors and for
 * code that prefers the explicit-extraction style.
 */
final class UlidExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    #[\NoDiscard('extract() returns the canonical ULID string — its return is the entire purpose of the call')]
    public static function extract(UlidValue $value): string
    {
        return ValueExtractor::extract($value);
    }
}
