<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(EventSourcedAggregateRoot::class)]
final class EventSourcedAggregateRootTest extends TestCase
{
    #[Test]
    public function eventSourcedAggregateIsEventSourceable(): void
    {
        $a = EsAggregate::create(new TestUlidId((new Ulid())->toBase32()));
        self::assertInstanceOf(EventSourceable::class, $a);
    }

    #[Test]
    public function replayReconstructsStateFromEvents(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $a->incrementBy(5);
        $a->incrementBy(7);
        $events = $a->pullRecordedEvents();

        $rehydrated = EsAggregate::create($id);
        $rehydrated->replay($events);

        self::assertSame(12, $rehydrated->total);
        self::assertSame(2, $rehydrated->version());
    }

    #[Test]
    public function replayDoesNotRecord(): void
    {
        $id = new TestUlidId((new Ulid())->toBase32());
        $a = EsAggregate::create($id);
        $a->replay([new Incremented(3), new Incremented(2)]);

        self::assertCount(0, $a->pullRecordedEvents());
        self::assertSame(5, $a->total);
    }
}

final class EsAggregate extends EventSourcedAggregateRoot
{
    public int $total = 0;

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    public function incrementBy(int $by): void
    {
        $this->recordThat(new Incremented($by));
    }

    private function applyIncremented(Incremented $e): void
    {
        $this->total += $e->by;
    }
}

final readonly class Incremented
{
    public function __construct(public int $by) {}
}
