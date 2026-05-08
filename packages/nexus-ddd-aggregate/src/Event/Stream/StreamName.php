<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Event\Stream;

use Monadial\Nexus\Ddd\Core\Value\StringValue;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Logical stream name — a value object wrapping a string. A stream is a
 * partition of the event store from which a consumer can subscribe to
 * events. Concrete physical storage (table layout) is determined by the
 * StreamStrategy + DBAL/Doctrine impl; domain code never sees physical
 * names.
 *
 * Extends `StringValue` to inherit `equals()` / `map()` / `flatMap()` from
 * the WrappedValue functor base — same pattern used by `UlidValue` and
 * `UuidValue` in nexus-ddd-core. The protected `getValue()` is exposed as
 * the public typed accessor `value(): string`.
 */
final readonly class StreamName extends StringValue
{
    public function value(): string
    {
        /** @var string */
        return $this->getValue();
    }
}
