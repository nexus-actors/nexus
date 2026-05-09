<?php

declare(strict_types=1);

// phpcs:disable

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

/**
 * @psalm-immutable
 * @psalm-suppress ImpureVariable
 */
final readonly class FactoryFixtureOrderId implements Identifier
{
    public function __construct(public string $raw) {}

    /** @psalm-pure */
    #[Override]
    public function value(): string
    {
        return $this->raw;
    }

    /** @psalm-pure */
    #[Override]
    public function equals(Identifier $other): bool
    {
        return $other instanceof self && $other->raw === $this->raw;
    }

    #[Override]
    public static function fromString(string $value): static
    {
        return new self($value);
    }
}

/** @psalm-immutable */
final readonly class FactoryFixtureOrderPlaced implements DomainEvent
{
    public function __construct(public string $customer) {}
}

/**
 * Good: factory only calls new self($id) and recordThat(). State is set
 * in apply() (omitted here — the fixture only checks factory behavior).
 *
 * @extends EventSourcedAggregateRoot<FactoryFixtureOrderId, FactoryFixtureOrderPlaced>
 */
final class GoodFactoryAggregate extends EventSourcedAggregateRoot
{
    private string $customer = '';

    public static function placeNew(FactoryFixtureOrderId $id, string $customer): self
    {
        $aggregate = new self($id);
        $aggregate->recordThat(new FactoryFixtureOrderPlaced($customer));

        return $aggregate;
    }

    #[Override]
    public function id(): FactoryFixtureOrderId
    {
        /** @var FactoryFixtureOrderId */
        return $this->id;
    }

    /** @psalm-suppress RedundantConditionGivenDocblockType */
    #[Override]
    protected function apply(DomainEvent $event): void
    {
        if ($event instanceof FactoryFixtureOrderPlaced) {
            $this->customer = $event->customer;
        }
    }
}

/**
 * Bad: factory directly assigns $this->customer. State must flow through
 * apply() after recordThat() — direct property assignment in the factory
 * desyncs the in-memory state from the recorded event stream.
 *
 * @extends EventSourcedAggregateRoot<FactoryFixtureOrderId, FactoryFixtureOrderPlaced>
 */
final class BadFactoryAggregate extends EventSourcedAggregateRoot
{
    private string $customer = '';

    public static function placeNew(FactoryFixtureOrderId $id, string $customer): self
    {
        $aggregate = new self($id);
        $aggregate->customer = $customer;
        $aggregate->recordThat(new FactoryFixtureOrderPlaced($customer));

        return $aggregate;
    }

    #[Override]
    public function id(): FactoryFixtureOrderId
    {
        /** @var FactoryFixtureOrderId */
        return $this->id;
    }

    /** @psalm-suppress RedundantConditionGivenDocblockType */
    #[Override]
    protected function apply(DomainEvent $event): void
    {
        if ($event instanceof FactoryFixtureOrderPlaced) {
            $this->customer = $event->customer;
        }
    }
}

/**
 * Bad: factory writes to the variable receiver inside a `match` arm.
 * Property writes inside any control-flow construct in the factory body
 * still trigger the rule.
 *
 * @extends EventSourcedAggregateRoot<FactoryFixtureOrderId, FactoryFixtureOrderPlaced>
 */
final class BadNestedFactoryAggregate extends EventSourcedAggregateRoot
{
    private string $customer = '';

    public static function placeIfMissing(FactoryFixtureOrderId $id, string $customer): self
    {
        $aggregate = new self($id);

        if ($customer !== '') {
            $aggregate->customer = $customer;
        }

        $aggregate->recordThat(new FactoryFixtureOrderPlaced($customer));

        return $aggregate;
    }

    #[Override]
    public function id(): FactoryFixtureOrderId
    {
        /** @var FactoryFixtureOrderId */
        return $this->id;
    }

    /** @psalm-suppress RedundantConditionGivenDocblockType */
    #[Override]
    protected function apply(DomainEvent $event): void
    {
        if ($event instanceof FactoryFixtureOrderPlaced) {
            $this->customer = $event->customer;
        }
    }
}

/**
 * Not an aggregate — assignment in static factory is fine.
 */
final class FactoryFixtureRegularValue
{
    public string $payload = '';

    public static function create(string $payload): self
    {
        $value = new self();
        $value->payload = $payload;

        return $value;
    }
}
