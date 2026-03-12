<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value\Extractor;

use Closure;
use Monadial\Nexus\Ddd\Value\StringValue;
use Monadial\Nexus\Ddd\Value\Value;

/**
 * @psalm-api
 *
 * Infrastructure-only extractor for StringValue.
 * Domain code must never import this class.
 */
final class ScalarStringExtractor
{
    private function __construct() {}

    public static function extract(StringValue $v): string
    {
        static $extractor;

        /** @psalm-suppress MixedAssignment, InaccessibleProperty */
        $extractor ??= Closure::bind(
            static fn(Value $v): mixed => $v->value,
            null,
            Value::class,
        );

        assert($extractor instanceof Closure);

        return (string) ($extractor)($v);
    }
}
