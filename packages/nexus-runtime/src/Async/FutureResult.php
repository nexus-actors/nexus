<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Async;

/**
 * @psalm-api
 *
 * Wraps the array result of Future::all() — Future<R> requires R to be an object.
 *
 * @template T
 */
final readonly class FutureResult
{
    /** @param array<array-key, T> $values */
    public function __construct(public array $values) {}
}
