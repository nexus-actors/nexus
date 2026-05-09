<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Strategy\Stateful;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Aggregate\Exception\AggregateAlreadyExistsException;
use Monadial\Nexus\Ddd\Aggregate\Strategy\StatefulPersister;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRootAccessor;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Override;

use function count;

/**
 * @psalm-api
 *
 * In-memory `StatefulPersister` for tests and single-process Fiber demos.
 *
 * Stores cloned aggregate snapshots — NOT a serialised representation —
 * keyed on `(aggregateClass, id)`. Each load returns a fresh clone so
 * concurrent test writers operate on independent instances. Production
 * stateful strategies (Doctrine, DBAL) replace this with row-level
 * UPDATE-WHERE-version SQL against a Valinor-mapped (or ORM-mapped)
 * state column.
 *
 * OCC mirrors the event-sourced flow: `expectedVersion = aggregateVersion
 * - count(recordedEvents)`. `expectedVersion === 0` means "fresh aggregate"
 * and takes the INSERT path; `expectedVersion > 0` means "loaded then
 * mutated" and takes the UPDATE-WHERE-version path. The accessor friend
 * class is reused because `popRecordedEventsFrom` / `extractVersion`
 * accept any `AggregateRoot` — not solely event-sourced ones.
 */
final class InMemoryStatefulStrategy implements StatefulPersister
{
    /** @var array<string, array{aggregate: StatefulAggregateRoot, version: int}> */
    private array $entries = [];

    private readonly EventSourcedAggregateRootAccessor $accessor;

    public function __construct()
    {
        $this->accessor = new EventSourcedAggregateRootAccessor();
    }

    /**
     * @template T of StatefulAggregateRoot
     *
     * @param class-string<T> $entityClass
     *
     * @return Option<T>
     *
     * @psalm-suppress InvalidReturnType,InvalidReturnStatement — the slot
     *                  is populated only by `persist()` which keys on
     *                  `$entity::class`; reading back through
     *                  `class-string<T>` therefore yields a `T`, but
     *                  Psalm cannot follow the runtime invariant.
     */
    #[Override]
    public function load(string $entityClass, Identifier $id): Option
    {
        $key = $this->keyFor($entityClass, $id);
        $entry = $this->entries[$key] ?? null;

        if ($entry === null) {
            return Option::none();
        }

        return Option::some(clone $entry['aggregate']);
    }

    /**
     * @throws OptimisticLockException
     * @throws AggregateAlreadyExistsException
     *
     * @psalm-suppress ArgumentTypeCoercion `$entity::class` is `class-string`
     *                  and `$entity->id()->value()` is documented as scalar
     *                  string; both are non-empty in practice but the type
     *                  system can't see that.
     */
    #[Override]
    public function persist(StatefulAggregateRoot $entity): void
    {
        $events = $this->accessor->popRecordedEventsFrom($entity);
        $aggregateVersion = $this->accessor->extractVersion($entity);
        $expectedVersion = $aggregateVersion - count($events);

        $key = $this->keyFor($entity::class, $entity->id());
        $existing = $this->entries[$key] ?? null;

        if ($expectedVersion === 0) {
            if ($existing !== null) {
                throw AggregateAlreadyExistsException::for($entity::class, $entity->id()->value());
            }

            $this->entries[$key] = ['aggregate' => clone $entity, 'version' => $aggregateVersion];

            return;
        }

        if ($existing === null || $existing['version'] !== $expectedVersion) {
            throw new OptimisticLockException(
                $entity::class,
                $entity->id()->value(),
                $expectedVersion,
                $existing['version'] ?? 0,
            );
        }

        $this->entries[$key] = ['aggregate' => clone $entity, 'version' => $aggregateVersion];
    }

    /** @param class-string $entityClass */
    private function keyFor(string $entityClass, Identifier $id): string
    {
        return $entityClass . '/' . $id->value();
    }
}
