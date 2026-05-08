<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Messaging\Context\ContextStorage;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Shared test class. Every ContextStorage implementation MUST extend this
 * and pass every test. Pins push/pop/current LIFO semantics — the minimum
 * contract every storage satisfies.
 *
 * Cross-fiber/cross-coroutine isolation is NOT part of this contract:
 * StaticStackContextStorage is a per-process stack and cannot satisfy
 * isolation. A future FiberLocalContextStorage / SwooleCoroutineContextStorage
 * will ship its own fiber/coroutine-isolation contract test.
 */
abstract class ContextStorageContractTest extends TestCase
{
    abstract protected function createStorage(): ContextStorage;

    #[Test]
    public function currentIsNoneOnFreshStorage(): void
    {
        $storage = $this->createStorage();
        self::assertTrue($storage->current()->isNone());
    }

    #[Test]
    public function pushThenCurrentExposesPushedContext(): void
    {
        $storage = $this->createStorage();
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock()));
        $fallback = new MessageContext(MessageMetadata::root($this->fixedClock()));
        $storage->push($ctx);
        self::assertSame($ctx, $storage->current()->getOrElse($fallback));
    }

    #[Test]
    public function popReturnsToPreviousContext(): void
    {
        $storage = $this->createStorage();
        $a = new MessageContext(MessageMetadata::root($this->fixedClock()));
        $b = new MessageContext(MessageMetadata::root($this->fixedClock()));
        $fallback = new MessageContext(MessageMetadata::root($this->fixedClock()));

        $storage->push($a);
        $storage->push($b);
        $storage->pop();

        self::assertSame($a, $storage->current()->getOrElse($fallback));
    }

    private function fixedClock(): ClockInterface
    {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');

        return new class ($now) implements ClockInterface {
            public function __construct(private DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
