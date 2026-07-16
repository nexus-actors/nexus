<?php

declare(strict_types=1);

namespace Swoole\Thread;

use ArrayAccess;
use Countable;

/**
 * Better-typed replacement stub for the native Swoole\Thread\Map class.
 *
 * The vendor stub (vendor/swoole/ide-helper/src/swoole/Swoole/Thread/Map.php)
 * implements ArrayAccess without `@implements ArrayAccess<TKey, TValue>`, so
 * Psalm resolves the interface templates to the unusable `TKey:ArrayAccess as
 * mixed`, producing InvalidArgument on every concrete key. This stub declares
 * the accurate signatures per the Swoole 6 documentation: string|int keys,
 * mixed (any serializable) values.
 *
 * This class is available only when PHP is compiled with Zend Thread Safety
 * (ZTS) enabled and Swoole is installed with the "--enable-swoole-thread"
 * configuration option.
 *
 * @since 6.0.0
 *
 * @implements ArrayAccess<array-key, mixed>
 */
final class Map implements ArrayAccess, Countable
{
    /**
     * @param array<array-key, mixed>|null $array
     */
    public function __construct(?array $array = null) {}

    /**
     * @param array-key $key
     */
    public function offsetGet(mixed $key): mixed {}

    /**
     * @param array-key $key
     */
    public function offsetExists(mixed $key): bool {}

    /**
     * @param array-key $key
     */
    public function offsetSet(mixed $key, mixed $value): void {}

    /**
     * @param array-key $key
     */
    public function offsetUnset(mixed $key): void {}

    /**
     * Find the key of the first entry holding the given value.
     */
    public function find(mixed $value): mixed {}

    public function count(): int {}

    /**
     * @param array-key $key
     * @param int|float $value
     * @return int|float
     */
    public function incr(mixed $key, mixed $value = 1): mixed {}

    /**
     * @param array-key $key
     * @param int|float $value
     * @return int|float
     */
    public function decr(mixed $key, mixed $value = 1): mixed {}

    /**
     * @param array-key $key
     */
    public function add(mixed $key, mixed $value): bool {}

    /**
     * @param array-key $key
     */
    public function update(mixed $key, mixed $value): bool {}

    public function clean(): void {}

    /**
     * @return list<array-key>
     */
    public function keys(): array {}

    /**
     * @return list<mixed>
     */
    public function values(): array {}

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array {}

    /**
     * Sort the map in ascending order.
     *
     * @since 6.0.1
     */
    public function sort(): void {}
}
