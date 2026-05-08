<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Context;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\ReplayingContextStorage;
use Monadial\Nexus\Ddd\Messaging\Exception\ReplayDispatchAttemptedException;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(ReplayingContextStorage::class)]
final class ReplayingContextStorageTest extends TestCase
{
    private NodeId $nodeId;

    #[Test]
    public function pushThrowsReplayDispatchAttempted(): void
    {
        $storage = new ReplayingContextStorage();
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));

        $this->expectException(ReplayDispatchAttemptedException::class);
        $storage->push($ctx);
    }

    #[Test]
    public function snapshotIsAlwaysEmpty(): void
    {
        self::assertSame([], (new ReplayingContextStorage())->snapshot());
    }

    #[Test]
    public function currentIsAlwaysNone(): void
    {
        self::assertTrue((new ReplayingContextStorage())->current()->isNone());
    }

    #[Test]
    public function popIsNoop(): void
    {
        $storage = new ReplayingContextStorage();
        $storage->pop();
        self::assertSame([], $storage->snapshot());
    }

    #[Test]
    public function restoreIsNoop(): void
    {
        $storage = new ReplayingContextStorage();
        $storage->restore([new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId))]);
        self::assertSame([], $storage->snapshot());
    }

    protected function setUp(): void
    {
        $this->nodeId = NodeId::generate();
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable {
return $this->now;
 }
        };
    }
}
