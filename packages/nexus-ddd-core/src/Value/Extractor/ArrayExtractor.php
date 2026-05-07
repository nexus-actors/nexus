<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value\Extractor;

use Monadial\Nexus\Ddd\Core\Value\ArrayValue;

/**
 * @psalm-api
 *
 * Typed extractor for ArrayValue subclasses. Preserves the array's templated
 * key/value types so callers receive a precisely-typed array.
 */
final class ArrayExtractor
{
    /** @psalm-suppress UnusedConstructor */
    private function __construct() {}

    /**
     * @template TKey of array-key
     * @template TValue
     * @param ArrayValue<TKey, TValue> $value
     * @return array<TKey, TValue>
     */
    #[\NoDiscard('extract() returns the inner array — its return is the entire purpose of the call')]
    public static function extract(ArrayValue $value): array
    {
        return ValueExtractor::extract($value);
    }
}
