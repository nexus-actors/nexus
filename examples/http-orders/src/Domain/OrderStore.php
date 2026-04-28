<?php

declare(strict_types=1);

namespace Examples\HttpOrders\Domain;

/**
 * Immutable in-memory state for the OrderActor.
 */
final readonly class OrderStore
{
    /**
     * @param array<int, Order> $orders
     */
    public function __construct(public int $nextId, public array $orders) {}

    public static function empty(): self
    {
        return new self(1, []);
    }

    public function withOrder(Order $order): self
    {
        return new self($this->nextId + 1, [...$this->orders, $order->id => $order]);
    }

    public function without(int $id): self
    {
        $orders = $this->orders;
        unset($orders[$id]);

        return new self($this->nextId, $orders);
    }
}
