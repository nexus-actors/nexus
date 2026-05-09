<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Strategy\EventSourcing;

use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\OnPredicate;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OnPredicate::class)]
final class OnPredicateTest extends TestCase
{
    #[Test]
    public function delegatesToPredicate(): void
    {
        $strategy = new OnPredicate(static fn(EventSourcedAggregateRoot $a, int $count): bool => $count > 50);
        $aggregate = self::createStub(EventSourcedAggregateRoot::class);
        self::assertFalse($strategy->shouldSnapshot($aggregate, 25));
        self::assertTrue($strategy->shouldSnapshot($aggregate, 100));
    }

    #[Test]
    public function passesAggregateAndCountToPredicate(): void
    {
        $captured = [];
        $strategy = new OnPredicate(static function (EventSourcedAggregateRoot $a, int $count) use (&$captured): bool {
            $captured[] = [$a, $count];

            return false;
        });
        $aggregate = self::createStub(EventSourcedAggregateRoot::class);
        $strategy->shouldSnapshot($aggregate, 42);
        self::assertCount(1, $captured);
        self::assertSame($aggregate, $captured[0][0]);
        self::assertSame(42, $captured[0][1]);
    }
}
