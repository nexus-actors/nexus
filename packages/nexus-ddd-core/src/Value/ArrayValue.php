<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Value;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @extends WrappedValue<array<TKey, TValue>>
 *
 * Subclasses specialize the array shape via @extends, e.g.:
 *
 *   /** @extends ArrayValue<int, OrderLine> *\/
 *   final readonly class OrderLines extends ArrayValue {
 *       public function asArray(): array {
 *           /** @var array<int, OrderLine> *\/
 *           return $this->getValue();
 *       }
 *   }
 */
abstract readonly class ArrayValue extends WrappedValue
{
    /**
     * @param array<TKey, TValue> $value
     */
    public function __construct(array $value)
    {
        parent::__construct($value);
    }
}
