<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\EventSourced;

use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SnapshotStrategy::class)]
final class SnapshotStrategyTest extends TestCase
{
    #[Test]
    public function everyNReturnsTrueOnMultiples(): void
    {
        $strategy = SnapshotStrategy::everyN(100);
        $state = new stdClass();
        $event = new stdClass();

        self::assertTrue($strategy->shouldSnapshot($state, $event, 100));
        self::assertTrue($strategy->shouldSnapshot($state, $event, 200));
    }

    #[Test]
    public function everyNReturnsFalseOnNonMultiples(): void
    {
        $strategy = SnapshotStrategy::everyN(100);
        $state = new stdClass();
        $event = new stdClass();

        self::assertFalse($strategy->shouldSnapshot($state, $event, 99));
        self::assertFalse($strategy->shouldSnapshot($state, $event, 101));
    }

    #[Test]
    public function neverAlwaysReturnsFalse(): void
    {
        $strategy = SnapshotStrategy::never();
        $state = new stdClass();
        $event = new stdClass();

        self::assertFalse($strategy->shouldSnapshot($state, $event, 1));
        self::assertFalse($strategy->shouldSnapshot($state, $event, 100));
        self::assertFalse($strategy->shouldSnapshot($state, $event, 1000));
    }

    #[Test]
    public function predicateDelegatesToClosure(): void
    {
        $strategy = SnapshotStrategy::predicate(
            static fn(object $state, object $event, int $seqNr): bool => $seqNr > 50,
        );
        $state = new stdClass();
        $event = new stdClass();

        self::assertFalse($strategy->shouldSnapshot($state, $event, 50));
        self::assertTrue($strategy->shouldSnapshot($state, $event, 51));
    }
}
