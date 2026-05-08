<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Context;

use DateTimeImmutable;
use Monadial\Nexus\Ddd\Messaging\Context\CurrentMessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\StaticStackContextStorage;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

#[CoversClass(CurrentMessageContext::class)]
final class CurrentMessageContextTest extends TestCase
{
    private NodeId $nodeId;

    protected function setUp(): void
    {
        $this->nodeId = NodeId::generate();
    }

    protected function tearDown(): void
    {
        CurrentMessageContext::resetStorage();
    }

    #[Test]
    public function defaultStorageIsStaticStack(): void
    {
        self::assertInstanceOf(StaticStackContextStorage::class, CurrentMessageContext::getStorage());
    }

    #[Test]
    public function setStorageSwapsBackingAndResetRestoresDefault(): void
    {
        $custom = new StaticStackContextStorage();
        CurrentMessageContext::setStorage($custom);
        self::assertSame($custom, CurrentMessageContext::getStorage());

        CurrentMessageContext::resetStorage();
        self::assertNotSame($custom, CurrentMessageContext::getStorage());
    }

    #[Test]
    public function currentReturnsNoneAtTopLevel(): void
    {
        self::assertTrue(CurrentMessageContext::current()->isNone());
    }

    #[Test]
    public function pushExposesContextThenPopRestoresEmpty(): void
    {
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));
        $fallback = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));
        CurrentMessageContext::push($ctx);
        self::assertSame($ctx, CurrentMessageContext::current()->getOrElse($fallback));
        CurrentMessageContext::pop();
        self::assertTrue(CurrentMessageContext::current()->isNone());
    }

    #[Test]
    public function withinPushesAndPopsInTryFinally(): void
    {
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));
        $observed = null;

        $result = CurrentMessageContext::within($ctx, static function () use (&$observed): string {
            $observed = CurrentMessageContext::current()->getOrCall(static fn() => null);

            return 'returned-value';
        });

        self::assertSame($ctx, $observed);
        self::assertSame('returned-value', $result);
        self::assertTrue(CurrentMessageContext::current()->isNone());
    }

    #[Test]
    public function withinPopsEvenWhenCallbackThrows(): void
    {
        $ctx = new MessageContext(MessageMetadata::root($this->fixedClock(), $this->nodeId));

        try {
            CurrentMessageContext::within($ctx, static function (): void {
                throw new RuntimeException('boom');
            });
            self::fail('expected exception');
        } catch (RuntimeException $expected) {
            self::assertSame('boom', $expected->getMessage());
        }

        self::assertTrue(CurrentMessageContext::current()->isNone());
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
