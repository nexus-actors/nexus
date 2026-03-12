<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Value\Extractor;

use Closure;
use DateTimeImmutable;
use Monadial\Nexus\Ddd\Value\DateTimeValue;
use Monadial\Nexus\Ddd\Value\Value;

/**
 * @psalm-api
 *
 * Infrastructure-only extractor for DateTimeValue.
 * Domain code must never import this class.
 */
final class ScalarDateTimeExtractor
{
    private function __construct() {}

    public static function extract(DateTimeValue $v): DateTimeImmutable
    {
        static $extractor;

        /** @psalm-suppress MixedAssignment, InaccessibleProperty */
        $extractor ??= Closure::bind(
            static fn(Value $v): mixed => $v->value,
            null,
            Value::class,
        );

        assert($extractor instanceof Closure);

        $result = ($extractor)($v);

        assert($result instanceof DateTimeImmutable);

        return $result;
    }
}
