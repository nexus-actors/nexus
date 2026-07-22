<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\UnsupportedSupervisionStrategyException;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REL-003 cross-runtime: scheduled backoff and all-for-one rejection under Swoole.
 */
final class SwooleSupervisionStrategyTest extends TestCase
{
    public function testExponentialBackoffDelaysTheRestart(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('swoole-backoff', $runtime);

        $preStarts = 0;

        $behavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            throw new RuntimeException('boom');
        })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$preStarts): Behavior {
            if ($signal instanceof PreStart) {
                $preStarts++;
            }

            return Behavior::same();
        });

        $ref = $system->spawn(
            Props::fromBehavior($behavior)->withSupervision(
                SupervisionStrategy::exponentialBackoff(
                    initialBackoff: Duration::millis(120),
                    maxBackoff: Duration::seconds(2),
                    maxRetries: 5,
                ),
            ),
            'flaky',
        );

        $atSixty = null;
        $atTwoSixty = null;

        $runtime->scheduleOnce(Duration::millis(10), static fn() => $ref->tell(new SwooleBoom()));
        $runtime->scheduleOnce(Duration::millis(60), static function () use (&$atSixty, &$preStarts): void {
            $atSixty = $preStarts;
        });
        $runtime->scheduleOnce(Duration::millis(260), static function () use (&$atTwoSixty, &$preStarts): void {
            $atTwoSixty = $preStarts;
        });
        $runtime->scheduleOnce(Duration::millis(360), static fn() => $system->shutdown(Duration::seconds(1)));

        $runtime->run();

        self::assertSame(1, $atSixty, 'Restart must be delayed by the backoff, not immediate');
        self::assertSame(2, $atTwoSixty, 'The restart must fire after the backoff delay elapses');
    }

    public function testSpawningWithAllForOneIsRejected(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('swoole-all-for-one', $runtime);

        $this->expectException(UnsupportedSupervisionStrategyException::class);

        $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
            ))->withSupervision(SupervisionStrategy::allForOne()),
            'nope',
        );
    }
}

/** @internal */
final readonly class SwooleBoom {}
