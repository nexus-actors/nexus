<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value\Extractor;

use Closure;
use Monadial\Nexus\Ddd\Value\UlidValue;
use Monadial\Nexus\Ddd\Value\Value;

/**
 * @psalm-api
 *
 * Infrastructure-only extractor for UlidValue.
 * Domain code must never import this class.
 */
final class ScalarUlidExtractor
{
    private function __construct() {}

    public static function extract(UlidValue $v): string
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
