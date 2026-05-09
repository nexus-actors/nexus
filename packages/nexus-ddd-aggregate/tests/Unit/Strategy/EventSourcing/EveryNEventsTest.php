<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Strategy\EventSourcing;

use InvalidArgumentException;
use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\EveryNEvents;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(EveryNEvents::class)]
final class EveryNEventsTest extends TestCase
{
    #[Test]
    public function returnsFalseWhenCountBelowThreshold(): void
    {
        $strategy = new EveryNEvents(10);
        $aggregate = self::createStub(EventSourcedAggregateRoot::class);
        self::assertFalse($strategy->shouldSnapshot($aggregate, 5));
        self::assertFalse($strategy->shouldSnapshot($aggregate, 9));
    }

    #[Test]
    public function returnsTrueWhenCountReachesThreshold(): void
    {
        $strategy = new EveryNEvents(10);
        $aggregate = self::createStub(EventSourcedAggregateRoot::class);
        self::assertTrue($strategy->shouldSnapshot($aggregate, 10));
    }

    #[Test]
    public function returnsTrueWhenCountExceedsThreshold(): void
    {
        $strategy = new EveryNEvents(10);
        $aggregate = self::createStub(EventSourcedAggregateRoot::class);
        self::assertTrue($strategy->shouldSnapshot($aggregate, 100));
    }

    #[Test]
    public function constructorRejectsZeroN(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EveryNEvents(0);
    }

    #[Test]
    public function constructorRejectsNegativeN(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EveryNEvents(-5);
    }
}
