<?php

declare(strict_types=1);

// phpcs:disable

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use ArrayIterator;
use Fp\Functional\Option\Option;
use Iterator;
use Monadial\Nexus\Ddd\Aggregate\Repository\AggregateRepository;
use Monadial\Nexus\Ddd\Aggregate\Repository\Attribute\BulkCommand;
use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

/**
 * @psalm-immutable
 * @psalm-suppress ImpureVariable
 */
final readonly class RepoFixtureBatchId implements Identifier
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

/**
 * @psalm-immutable
 * @psalm-suppress ImpureVariable
 */
final readonly class RepoFixtureOrderId implements Identifier
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

/**
 * @extends StatefulAggregateRoot<RepoFixtureOrderId, DomainEvent>
 */
final class RepoFixtureOrder extends StatefulAggregateRoot
{
    #[Override]
    public function id(): RepoFixtureOrderId
    {
        /** @var RepoFixtureOrderId */
        return $this->id;
    }
}

/**
 * Good: only the find()/save() shape from the AggregateRepository interface.
 *
 * @implements AggregateRepository<RepoFixtureOrder>
 */
final class GoodMinimalRepository implements AggregateRepository
{
    /**
     * @return Option<RepoFixtureOrder>
     */
    #[Override]
    public function find(Identifier $id): Option
    {
        return Option::none();
    }

    #[Override]
    public function save(AggregateRoot $aggregate): void {}
}

/**
 * Good: bulk method exists but is annotated #[BulkCommand].
 *
 * @implements AggregateRepository<RepoFixtureOrder>
 */
final class GoodBulkRepository implements AggregateRepository
{
    /**
     * @return Option<RepoFixtureOrder>
     */
    #[Override]
    public function find(Identifier $id): Option
    {
        return Option::none();
    }

    #[Override]
    public function save(AggregateRoot $aggregate): void {}

    /**
     * @return iterable<RepoFixtureOrder>
     */
    #[BulkCommand('migration job rewrites every order in batch')]
    public function inBatch(RepoFixtureBatchId $batchId): iterable
    {
        return [];
    }
}

/**
 * Bad: returns iterable but is not annotated. This is a query in
 * disguise — should go through QueryBus + projection.
 *
 * @implements AggregateRepository<RepoFixtureOrder>
 */
final class BadIterableRepository implements AggregateRepository
{
    /**
     * @return Option<RepoFixtureOrder>
     */
    #[Override]
    public function find(Identifier $id): Option
    {
        return Option::none();
    }

    #[Override]
    public function save(AggregateRoot $aggregate): void {}

    /**
     * @return iterable<RepoFixtureOrder>
     */
    public function findAllPending(): iterable
    {
        return [];
    }
}

/**
 * Bad: returns array<…> — same problem as iterable.
 *
 * @implements AggregateRepository<RepoFixtureOrder>
 */
final class BadArrayRepository implements AggregateRepository
{
    /**
     * @return Option<RepoFixtureOrder>
     */
    #[Override]
    public function find(Identifier $id): Option
    {
        return Option::none();
    }

    #[Override]
    public function save(AggregateRoot $aggregate): void {}

    /**
     * @return array<RepoFixtureOrder>
     */
    public function findByCustomer(string $customer): array
    {
        return [];
    }
}

/**
 * Bad: returns Iterator — same problem.
 *
 * @implements AggregateRepository<RepoFixtureOrder>
 */
final class BadIteratorRepository implements AggregateRepository
{
    /**
     * @return Option<RepoFixtureOrder>
     */
    #[Override]
    public function find(Identifier $id): Option
    {
        return Option::none();
    }

    #[Override]
    public function save(AggregateRoot $aggregate): void {}

    /**
     * @return Iterator<RepoFixtureOrder>
     */
    public function streamAll(): Iterator
    {
        return new ArrayIterator([]);
    }
}

/**
 * Not a repository — return-type rule does not apply.
 */
final class RepoFixtureRegularService
{
    /**
     * @return iterable<RepoFixtureOrder>
     */
    public function listOrders(): iterable
    {
        return [];
    }
}
