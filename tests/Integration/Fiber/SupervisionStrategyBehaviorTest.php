<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\UnsupportedSupervisionStrategyException;
use Monadial\Nexus\Core\Lifecycle\ChildFailed;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Supervision\Directive;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * REL-003: supervision strategies must behave as configured — scheduled exponential
 * backoff, escalation to the parent, and fail-fast rejection of the unsupported
 * all-for-one strategy.
 */
final class SupervisionStrategyBehaviorTest extends TestCase
{
    #[Test]
    public function exponentialBackoffDelaysTheRestartInsteadOfRestartingImmediately(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('backoff', $runtime);

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

        $runtime->scheduleOnce(Duration::millis(10), static fn() => $ref->tell(new Boom()));

        // At 60 ms the 120 ms backoff has NOT elapsed — still the original incarnation.
        $atSixty = null;
        $runtime->scheduleOnce(Duration::millis(60), static function () use (&$atSixty, &$preStarts): void {
            $atSixty = $preStarts;
        });

        // By 260 ms the delayed restart has fired.
        $atTwoSixty = null;
        $runtime->scheduleOnce(Duration::millis(260), static function () use (&$atTwoSixty, &$preStarts): void {
            $atTwoSixty = $preStarts;
        });

        $runtime->scheduleOnce(Duration::millis(360), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // Immediate restart would have already re-fired PreStart by 60 ms; the backoff delays it.
        self::assertSame(1, $atSixty, 'Restart must be delayed by the backoff, not immediate');
        self::assertSame(2, $atTwoSixty, 'The restart must fire after the backoff delay elapses');
    }

    #[Test]
    public function escalateDeliversChildFailedToTheParent(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('escalate', $runtime);

        /** @var list<string> $childFailures */
        $childFailures = [];

        $parent = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof SpawnFailingChild) {
                        $child = $ctx->spawn(
                            Props::fromBehavior(Behavior::receive(
                                static function (ActorContext $c, object $m): Behavior {
                                    throw new RuntimeException('child boom');
                                },
                            ))->withSupervision(
                                SupervisionStrategy::oneForOne(
                                    decider: static fn(): Directive => Directive::Escalate,
                                ),
                            ),
                            'child',
                        );
                        $child->tell(new Boom());
                    }

                    return Behavior::same();
                })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$childFailures): Behavior {
                    if ($signal instanceof ChildFailed) {
                        $childFailures[] = $signal->cause->getMessage();
                    }

                    return Behavior::same();
                }),
            ),
            'parent',
        );

        $runtime->scheduleOnce(Duration::millis(20), static fn() => $parent->tell(new SpawnFailingChild()));
        $runtime->scheduleOnce(Duration::millis(160), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertContains('child boom', $childFailures);
    }

    #[Test]
    public function spawningWithAllForOneIsRejected(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('all-for-one-reject', $runtime);

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
final readonly class Boom {}

/** @internal */
final readonly class SpawnFailingChild {}
