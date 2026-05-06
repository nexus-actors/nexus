<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AggregateRoot::class)]
final class AggregateRootTest extends TestCase
{
    #[Test]
    public function recordThatInvokesApplyAndAppendsEvent(): void
    {
        $a = TestAggregate::create(self::ulid());
        $a->doSomething('hello');

        self::assertSame('hello', $a->state);
        $events = $a->pullRecordedEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SomethingHappened::class, $events[0]);
    }

    #[Test]
    public function pullRecordedEventsClearsTheBuffer(): void
    {
        $a = TestAggregate::create(self::ulid());
        $a->doSomething('a');
        $a->pullRecordedEvents();
        self::assertCount(0, $a->pullRecordedEvents());
    }

    #[Test]
    public function aggregateIsBothEntityAndEventSourceable(): void
    {
        $a = TestAggregate::create(self::ulid());
        self::assertInstanceOf(Entity::class, $a);
        self::assertInstanceOf(EventSourceable::class, $a);
    }

    #[Test]
    public function entityEqualityRequiresSameTypeAndId(): void
    {
        $id = self::ulid();
        $a = TestAggregate::create($id);
        $b = TestAggregate::create($id);
        $c = TestAggregate::create(self::ulid());
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function defaultStateVersionIsOne(): void
    {
        $a = TestAggregate::create(self::ulid());
        self::assertSame(1, $a->stateVersion());
    }

    private static function ulid(): UlidValue
    {
        return new UlidValue((new Ulid())->toBase32());
    }
}

final class TestAggregate extends AggregateRoot
{
    public string $state = '';

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    public function doSomething(string $value): void
    {
        $this->recordThat(new SomethingHappened($value));
    }

    private function applySomethingHappened(SomethingHappened $e): void
    {
        $this->state = $e->payload;
    }
}

final readonly class SomethingHappened
{
    public function __construct(public string $payload) {}
}
