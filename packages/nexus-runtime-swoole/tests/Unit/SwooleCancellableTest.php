<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit;

use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\Runtime\Swoole\SwooleCancellable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Timer;

use function Swoole\Coroutine\run;

#[CoversClass(SwooleCancellable::class)]
final class SwooleCancellableTest extends TestCase
{
    #[Test]
    public function it_implements_cancellable(): void
    {
        run(static function (): void {
            $timerId = Timer::after(60_000, static function (): void {});
            $cancellable = new SwooleCancellable($timerId);
            self::assertInstanceOf(Cancellable::class, $cancellable);
            Timer::clear($timerId);
        });
    }

    #[Test]
    public function it_is_not_cancelled_initially(): void
    {
        run(static function (): void {
            $timerId = Timer::after(60_000, static function (): void {});
            $cancellable = new SwooleCancellable($timerId);
            self::assertFalse($cancellable->isCancelled());
            Timer::clear($timerId);
        });
    }

    #[Test]
    public function cancel_clears_timer_and_sets_state(): void
    {
        run(static function (): void {
            $fired = false;
            $timerId = Timer::after(1, static function () use (&$fired): void {
                $fired = true;
            });
            $cancellable = new SwooleCancellable($timerId);
            $cancellable->cancel();
            self::assertTrue($cancellable->isCancelled());

            Coroutine::sleep(0.05);
            self::assertFalse($fired);
        });
    }

    #[Test]
    public function double_cancel_is_idempotent(): void
    {
        run(static function (): void {
            $timerId = Timer::after(60_000, static function (): void {});
            $cancellable = new SwooleCancellable($timerId);
            $cancellable->cancel();
            $cancellable->cancel();
            self::assertTrue($cancellable->isCancelled());
        });
    }
}
