<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Tests\Unit\Async;

use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Exception\FutureCancelledException;
use Monadial\Nexus\Runtime\Tests\Support\TestFutureSlot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Future::class)]
final class FutureTest extends TestCase
{
    #[Test]
    public function await_returns_resolved_value(): void
    {
        $slot = new TestFutureSlot();
        $slot->resolve((object) ['ok' => true]);

        $future = new Future($slot);

        self::assertTrue($future->await()->ok);
    }

    #[Test]
    public function cancel_then_await_throws_cancelled_exception(): void
    {
        $slot = new TestFutureSlot();
        $future = new Future($slot);

        $future->cancel();

        $this->expectException(FutureCancelledException::class);
        $future->await();
    }

    #[Test]
    public function onCancel_registers_callback_invoked_on_cancel(): void
    {
        $slot = new TestFutureSlot();
        $future = new Future($slot);
        $invoked = false;

        $future->onCancel(static function () use (&$invoked): void {
            $invoked = true;
        });
        $future->cancel();

        self::assertTrue($invoked);
    }
}
