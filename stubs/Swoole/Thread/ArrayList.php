<?php

declare(strict_types=1);

namespace Swoole\Thread;

use ArrayAccess;
use Countable;

/**
 * Better-typed replacement stub for the native Swoole\Thread\ArrayList class.
 *
 * The vendor stub (vendor/swoole/ide-helper/src/swoole/Swoole/Thread/ArrayList.php)
 * implements ArrayAccess without `@implements ArrayAccess<TKey, TValue>`, so
 * Psalm resolves the interface templates to the unusable `TKey:ArrayAccess as
 * mixed`, producing InvalidArgument on every concrete key. This stub declares
 * the accurate signatures per the Swoole 6 documentation: int keys (null key
 * appends), mixed (any serializable) values.
 *
 * This class is available only when PHP is compiled with Zend Thread Safety
 * (ZTS) enabled and Swoole is installed with the "--enable-swoole-thread"
 * configuration option.
 *
 * @since 6.0.0
 *
 * @implements ArrayAccess<int, mixed>
 */
final class ArrayList implements ArrayAccess, Countable
{
    public int $id = 0;

    /**
     * @param list<mixed>|null $array
     */
    public function __construct(?array $array = null) {}

    /**
     * @param int $key
     */
    public function offsetGet(mixed $key): mixed {}

    /**
     * @param int $key
     */
    public function offsetExists(mixed $key): bool {}

    /**
     * @param int|null $key Null appends to the end of the list.
     */
    public function offsetSet(mixed $key, mixed $value): void {}

    /**
     * @param int $key
     */
    public function offsetUnset(mixed $key): void {}

    /**
     * Find the index of the first entry holding the given value.
     */
    public function find(mixed $value): int {}

    /**
     * @param int $key
     * @param int|float $value
     * @return int|float
     */
    public function incr(mixed $key, mixed $value = 1): mixed {}

    /**
     * @param int $key
     * @param int|float $value
     * @return int|float
     */
    public function decr(mixed $key, mixed $value = 1): mixed {}

    public function clean(): void {}

    public function count(): int {}

    /**
     * @return list<mixed>
     */
    public function toArray(): array {}

    /**
     * Sort the list in ascending order, without maintaining index association.
     *
     * @since 6.0.1
     */
    public function sort(): void {}
}
