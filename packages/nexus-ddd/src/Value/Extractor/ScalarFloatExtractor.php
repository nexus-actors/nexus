<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value\Extractor;

use Closure;
use Monadial\Nexus\Ddd\Value\FloatValue;
use Monadial\Nexus\Ddd\Value\Value;

/**
 * @psalm-api
 *
 * Infrastructure-only extractor for FloatValue.
 * Domain code must never import this class.
 */
final class ScalarFloatExtractor
{
    private function __construct() {}

    public static function extract(FloatValue $v): float
    {
        static $extractor;

        /** @psalm-suppress MixedAssignment, InaccessibleProperty */
        $extractor ??= Closure::bind(
            static fn(Value $v): mixed => $v->value,
            null,
            Value::class,
        );

        assert($extractor instanceof Closure);

        return (float) ($extractor)($v);
    }
}
