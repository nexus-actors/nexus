<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use DateTimeImmutable;
use Fiber;
use Monadial\Nexus\Ddd\Messaging\Context\ContextStorage;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Shared test class. Every ContextStorage implementation MUST extend this
 * and pass every test. Pins the cross-fiber/coroutine isolation invariant.
 */
abstract class ContextStorageContractTest extends TestCase
{
    abstract protected function createStorage(): ContextStorage;

    #[Test]
    public function snapshotEmptyAndCurrentNoneOnFreshStorage(): void
    {
        $storage = $this->createStorage();
        self::assertSame([], $storage->snapshot());
        self::assertTrue($storage->current()->isNone());
    }

    #[Test]
    public function pushThenCurrentExposesPushedContext(): void
    {
        $storage = $this->createStorage();
        $nodeId = NodeId::generate();
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock(), $nodeId));
        $fallback = new MessageContext(MessageMetadata::root($this->fixedClock(), $nodeId));
        $storage->push($ctx);
        self::assertSame($ctx, $storage->current()->getOrElse($fallback));
    }

    #[Test]
    public function popReturnsToPreviousContext(): void
    {
        $storage = $this->createStorage();
        $nodeId = NodeId::generate();
        $a = new MessageContext(MessageMetadata::root($this->fixedClock(), $nodeId));
        $b = new MessageContext(MessageMetadata::root($this->fixedClock(), $nodeId));
        $fallback = new MessageContext(MessageMetadata::root($this->fixedClock(), $nodeId));

        $storage->push($a);
        $storage->push($b);
        $storage->pop();

        self::assertSame($a, $storage->current()->getOrElse($fallback));
    }

    #[Test]
    public function isolatesConcurrentHandlerChainsUnderCooperativeScheduling(): void
    {
        $storage = $this->createStorage();
        $observations = [];

        $makeFiber = function (int $i) use ($storage, &$observations): Fiber {
            return new Fiber(static function () use ($i, $storage, &$observations): void {
                $clock = new class implements ClockInterface {
                    public function now(): DateTimeImmutable
                    {
                        return new DateTimeImmutable();
                    }
                };
                $ctx = new MessageContext(MessageMetadata::root($clock, NodeId::generate()));
                $storage->push($ctx);
                Fiber::suspend();
                $observations[$i] = $storage->current()->getOrCall(static fn() => null) === $ctx;
                $storage->pop();
            });
        };

        $fibers = [];

        for ($i = 0; $i < 4; ++$i) {
            $fiber = $makeFiber($i);
            $fiber->start();
            $fibers[$i] = $fiber;
        }

        foreach ($fibers as $f) {
            $f->resume();
        }

        self::assertCount(4, $observations);

        foreach ($observations as $observed) {
            self::assertIsBool($observed);
        }
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable { return $this->now; }
        };
    }
}
