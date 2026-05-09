<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Aggregate\Tests\Unit\Strategy\EventSourcing;

use Monadial\Nexus\Ddd\Aggregate\Strategy\EventSourcing\NeverSnapshot;
use Monadial\Nexus\Ddd\Core\Aggregate\EventSourcedAggregateRoot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NeverSnapshot::class)]
final class NeverSnapshotTest extends TestCase
{
    #[Test]
    public function alwaysReturnsFalse(): void
    {
        $strategy = new NeverSnapshot();
        $aggregate = self::createStub(EventSourcedAggregateRoot::class);
        self::assertFalse($strategy->shouldSnapshot($aggregate, 0));
        self::assertFalse($strategy->shouldSnapshot($aggregate, 100));
        self::assertFalse($strategy->shouldSnapshot($aggregate, 1000000));
    }
}
