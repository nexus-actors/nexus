<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Fiber\Tests\Unit;

use Fiber;
use Monadial\Nexus\Runtime\Fiber\FiberFutureSlot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(FiberFutureSlot::class)]
final class FiberFutureSlotTest extends TestCase
{
    #[Test]
    public function resolve_then_await_returns_value(): void
    {
        $slot = new FiberFutureSlot(static function (): void {});
        $value = new stdClass();
        $value->name = 'test';

        $slot->resolve($value);

        self::assertTrue($slot->isResolved());
        self::assertSame($value, $slot->await());
    }

    #[Test]
    public function fail_then_await_throws(): void
    {
        $slot = new FiberFutureSlot(static function (): void {});
        $slot->fail(new RuntimeException('boom'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $slot->await();
    }

    #[Test]
    public function resolve_is_idempotent(): void
    {
        $slot = new FiberFutureSlot(static function (): void {});
        $first = new stdClass();
        $first->val = 'first';
        $second = new stdClass();
        $second->val = 'second';

        $slot->resolve($first);
        $slot->resolve($second);

        self::assertSame($first, $slot->await());
    }

    #[Test]
    public function await_suspends_fiber_until_resolved(): void
    {
        $wakeups = 0;
        $slot = new FiberFutureSlot(static function () use (&$wakeups): void {
            $wakeups++;
        });

        $result = null;
        $fiber = new Fiber(static function () use ($slot, &$result): void {
            $result = $slot->await();
        });

        $fiber->start();
        self::assertTrue($fiber->isSuspended());
        self::assertNull($result);

        $value = new stdClass();
        $value->answer = 42;
        $slot->resolve($value);
        self::assertSame(1, $wakeups);

        // Resume the fiber so it can read the result
        $fiber->resume();
        self::assertTrue($fiber->isTerminated());
        self::assertSame(42, $result->answer);
    }
}
