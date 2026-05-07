<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Aggregate;

use Monadial\Nexus\Ddd\Core\Aggregate\AggregateRoot;
use Monadial\Nexus\Ddd\Core\Aggregate\StatefulAggregateRoot;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Core\Entity\Entity;
use Monadial\Nexus\Ddd\Core\Entity\EventSourceable;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUlidId;
use Monadial\Nexus\Ddd\Core\Value\UlidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

#[CoversClass(AggregateRoot::class)]
final class AggregateRootTest extends TestCase
{
    #[Test]
    public function recordThatAppendsEventAndBumpsVersionWithoutApplyDispatch(): void
    {
        $a = StatefulSample::create(self::ulid());
        $a->setName('Ada');

        // State-stored aggregate mutates state directly in the command method;
        // no applyXxx is required (or invoked).
        self::assertSame('Ada', $a->name);
        $events = $a->pullRecordedEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(NameSet::class, $events[0]);
        self::assertSame(1, $a->version());
    }

    #[Test]
    public function pullRecordedEventsClearsTheBuffer(): void
    {
        $a = StatefulSample::create(self::ulid());
        $a->setName('a');
        (void) $a->pullRecordedEvents();   // intentional drain
        self::assertCount(0, $a->pullRecordedEvents());
    }

    #[Test]
    public function aggregateIsEntityButNotEventSourceableAtBaseLevel(): void
    {
        $a = StatefulSample::create(self::ulid());
        self::assertInstanceOf(Entity::class, $a);
        // State-stored aggregates are NOT EventSourceable.
        self::assertNotInstanceOf(EventSourceable::class, $a);
    }

    #[Test]
    public function entityEqualityRequiresSameTypeAndId(): void
    {
        $id = self::ulid();
        $a = StatefulSample::create($id);
        $b = StatefulSample::create($id);
        $c = StatefulSample::create(self::ulid());
        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }

    #[Test]
    public function checkPassesWhenInvariantHolds(): void
    {
        $a = StatefulSample::create(self::ulid());
        $a->setName('Ada');   // non-empty — invariant holds

        self::assertSame('Ada', $a->name);
    }

    #[Test]
    public function checkThrowsTypedDomainExceptionWhenInvariantViolated(): void
    {
        $a = StatefulSample::create(self::ulid());

        $this->expectException(NameMustBeNonEmpty::class);
        $a->setName('');   // empty — invariant violated
    }

    #[Test]
    public function checkThrowsAdHocDomainExceptionForStringRule(): void
    {
        $a = StatefulSample::create(self::ulid());

        try {
            $a->setNameWithAdHocRule('');
            self::fail('expected DomainException');
        } catch (DomainException $e) {
            self::assertSame('name must not be blank', $e->getMessage());
        }
    }

    private static function ulid(): UlidValue
    {
        return new TestUlidId((new Ulid())->toBase32());
    }
}

final class StatefulSample extends StatefulAggregateRoot
{
    public string $name = '';

    public static function create(Identifier $id): self
    {
        return new self($id);
    }

    #[\Override]
    public function id(): Identifier
    {
        return $this->id;
    }

    public function setName(string $name): void
    {
        $this->check($name !== '', new NameMustBeNonEmpty());
        $this->name = $name;
        $this->recordThat(new NameSet($name));
    }

    public function setNameWithAdHocRule(string $name): void
    {
        $this->check($name !== '', 'name must not be blank');
        $this->name = $name;
        $this->recordThat(new NameSet($name));
    }
}

final readonly class NameSet implements DomainEvent
{
    public function __construct(public string $name) {}
}

final class NameMustBeNonEmpty extends DomainException
{
    public function __construct()
    {
        parent::__construct('name must not be empty');
    }
}
