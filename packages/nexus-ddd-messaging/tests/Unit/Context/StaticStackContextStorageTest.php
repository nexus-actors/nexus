<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Context;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Messaging\Context\ContextStorage;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\StaticStackContextStorage;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\ContextStorageContractTest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;

#[CoversClass(StaticStackContextStorage::class)]
final class StaticStackContextStorageTest extends ContextStorageContractTest
{
    private NodeId $nodeId;

    #[Test]
    public function snapshotIsEmptyOnCreation(): void
    {
        $storage = new StaticStackContextStorage();
        self::assertSame([], $storage->snapshot());
        self::assertTrue($storage->current()->isNone());
    }

    #[Test]
    public function pushPopMaintainsLifoOrder(): void
    {
        $storage = new StaticStackContextStorage();
        $a = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));
        $b = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));
        $fallback = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));

        $storage->push($a);
        $storage->push($b);
        self::assertSame($b, $storage->current()->getOrElse($fallback));

        $storage->pop();
        self::assertSame($a, $storage->current()->getOrElse($fallback));

        $storage->pop();
        self::assertTrue($storage->current()->isNone());
    }

    #[Test]
    public function restoreReplacesStackEntirely(): void
    {
        $storage = new StaticStackContextStorage();
        $a = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));
        $b = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));

        $storage->push($a);
        $storage->restore([$b]);

        self::assertSame([$b], $storage->snapshot());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->nodeId = NodeId::generate();
    }

    #[Override]
    protected function createStorage(): ContextStorage
    {
        return new StaticStackContextStorage();
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
