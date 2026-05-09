<?php

declare(strict_types=1);

// phpcs:disable

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
use Override;

/**
 * @psalm-immutable
 * @psalm-suppress ImpureVariable
 */
final readonly class EmitsFixtureCustomerId implements Identifier
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
final readonly class EmitsFixtureCustomerNamed implements DomainEvent
{
    public function __construct(public string $name) {}
}

/** @psalm-immutable */
final readonly class EmitsFixtureNotifyCommand implements Command
{
    public function __construct(public string $note) {}
}

/**
 * @psalm-immutable
 * @implements Query<int>
 */
final readonly class EmitsFixtureCountQuery implements Query
{
    public function __construct(public string $criterion) {}
}

/**
 * Good: only calls recordThat() — event emission is fine.
 *
 * @extends StatefulAggregateRoot<EmitsFixtureCustomerId, EmitsFixtureCustomerNamed>
 */
final class GoodEmitsAggregate extends StatefulAggregateRoot
{
    public static function open(EmitsFixtureCustomerId $id, string $name): self
    {
        $aggregate = new self($id);
        $aggregate->record(new EmitsFixtureCustomerNamed($name));

        return $aggregate;
    }

    public function rename(string $name): void
    {
        $this->record(new EmitsFixtureCustomerNamed($name));
    }

    private function record(DomainEvent $event): void
    {
        /** @var EmitsFixtureCustomerNamed $event */
        $this->recordThat($event);
    }

    #[Override]
    public function id(): EmitsFixtureCustomerId
    {
        /** @var EmitsFixtureCustomerId */
        return $this->id;
    }
}

/**
 * Bad: aggregate calls CommandBus from inside its own method. Cross-aggregate
 * flow goes through process managers, not direct dispatch from inside the
 * aggregate boundary.
 *
 * @extends StatefulAggregateRoot<EmitsFixtureCustomerId, EmitsFixtureCustomerNamed>
 */
final class BadEmitsAggregateUsesCommandBus extends StatefulAggregateRoot
{
    public function __construct(
        EmitsFixtureCustomerId $id,
        private readonly CommandBus $commandBus,
    ) {
        parent::__construct($id);
    }

    public function rename(string $name): void
    {
        $this->commandBus->dispatchCommand(new EmitsFixtureNotifyCommand($name));
    }

    #[Override]
    public function id(): EmitsFixtureCustomerId
    {
        /** @var EmitsFixtureCustomerId */
        return $this->id;
    }
}

/**
 * Bad: aggregate publishes events through EventBus directly instead of
 * recordThat(). The event store / outbox owns publication; aggregates
 * record events.
 *
 * @extends StatefulAggregateRoot<EmitsFixtureCustomerId, EmitsFixtureCustomerNamed>
 */
final class BadEmitsAggregateUsesEventBus extends StatefulAggregateRoot
{
    public function __construct(
        EmitsFixtureCustomerId $id,
        private readonly EventBus $eventBus,
    ) {
        parent::__construct($id);
    }

    public function rename(string $name): void
    {
        $this->eventBus->publishEvent(new EmitsFixtureCustomerNamed($name));
    }

    #[Override]
    public function id(): EmitsFixtureCustomerId
    {
        /** @var EmitsFixtureCustomerId */
        return $this->id;
    }
}

/**
 * Bad: aggregate runs a query through QueryBus. Aggregates derive state
 * from their own stream, not from cross-aggregate reads.
 *
 * @extends StatefulAggregateRoot<EmitsFixtureCustomerId, EmitsFixtureCustomerNamed>
 */
final class BadEmitsAggregateUsesQueryBus extends StatefulAggregateRoot
{
    public function __construct(
        EmitsFixtureCustomerId $id,
        private readonly QueryBus $queryBus,
    ) {
        parent::__construct($id);
    }

    public function rename(string $name): void
    {
        $count = $this->queryBus->dispatchQuery(new EmitsFixtureCountQuery($name));
        if ($count > 0) {
            return;
        }
    }

    #[Override]
    public function id(): EmitsFixtureCustomerId
    {
        /** @var EmitsFixtureCustomerId */
        return $this->id;
    }
}

/**
 * Not an aggregate — bus calls are fine here (e.g. application services).
 */
final class EmitsFixtureRegularService
{
    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function notify(string $note): void
    {
        $this->commandBus->dispatchCommand(new EmitsFixtureNotifyCommand($note));
    }
}
