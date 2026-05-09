<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Smoke\Fixtures;

use LogicException;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Override;

use function sprintf;

/**
 * @psalm-api
 *
 * @extends EventSourcedAggregateRoot<OrderId, OrderPlaced|OrderLineAdded>
 *
 * Smoke-test event-sourced aggregate. Uses the shipped single-`apply()`
 * + `match (true)` pattern (see `EventSourcedAggregateRoot`'s class
 * docblock) — per-event helpers (`whenPlaced`, `whenLineAdded`) are
 * private and named only for organization; they are NOT load-bearing
 * (no reflection-based dispatch). The match's `default` arm throws on
 * unknown events to make replay-shape mismatches fail loudly.
 *
 * State (`$customer`, `$lines`) is private; smoke tests read it via
 * reflection so the aggregate's API surface stays free of getters
 * (matches the `NoGettersSettersOnAggregateRule` follow-up enforced
 * in Phase 13).
 */
final class Order extends EventSourcedAggregateRoot
{
    private CustomerId $customer;

    private OrderLines $lines;

    private function __construct(OrderId $id)
    {
        parent::__construct($id);
    }

    public static function placeNew(OrderId $id, CustomerId $customer, OrderLines $lines): self
    {
        $order = new self($id);
        $order->recordThat(new OrderPlaced($id, $customer, $lines));

        return $order;
    }

    public function addLine(OrderLine $line): void
    {
        $this->recordThat(new OrderLineAdded($this->id(), $line));
    }

    #[Override]
    public function id(): OrderId
    {
        /** @var OrderId */
        return $this->id;
    }

    #[Override]
    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof OrderPlaced => $this->whenPlaced($event),
            $event instanceof OrderLineAdded => $this->whenLineAdded($event),
            default => throw new LogicException(sprintf('Unexpected event %s for Order aggregate.', $event::class)),
        };
    }

    private function whenPlaced(OrderPlaced $e): void
    {
        $this->customer = $e->customerId;
        $this->lines = $e->lines;
    }

    private function whenLineAdded(OrderLineAdded $e): void
    {
        $this->lines = $this->lines->withAdded($e->line);
    }
}
