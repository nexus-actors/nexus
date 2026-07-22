<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\ActorNameExistsException;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Lifecycle\Terminated;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REL-002: death watch (Terminated propagation), parent child-map pruning, and
 * actor-name reuse after termination.
 */
final class DeathWatchTest extends TestCase
{
    #[Test]
    public function watcherReceivesTerminatedWhenWatchedActorStops(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('death-watch', $runtime);

        $target = $system->spawn(self::plainProps(), 'target');

        /** @var list<string> $terminatedPaths */
        $terminatedPaths = [];

        $watcher = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof WatchThat) {
                        $ctx->watch($msg->target);
                    }

                    return Behavior::same();
                })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$terminatedPaths): Behavior {
                    if ($signal instanceof Terminated) {
                        $terminatedPaths[] = (string) $signal->ref->path();
                    }

                    return Behavior::same();
                }),
            ),
            'watcher',
        );

        $runtime->scheduleOnce(Duration::millis(20), static fn() => $watcher->tell(new WatchThat($target)));
        $runtime->scheduleOnce(Duration::millis(60), static fn() => $system->stop($target));
        $runtime->scheduleOnce(Duration::millis(160), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertContains('/user/target', $terminatedPaths);
    }

    #[Test]
    public function watchingAnAlreadyStoppedActorDeliversTerminatedImmediately(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('death-watch-dead', $runtime);

        $target = $system->spawn(self::plainProps(), 'target');

        /** @var list<string> $terminatedPaths */
        $terminatedPaths = [];

        $watcher = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof WatchThat) {
                        $ctx->watch($msg->target);
                    }

                    return Behavior::same();
                })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$terminatedPaths): Behavior {
                    if ($signal instanceof Terminated) {
                        $terminatedPaths[] = (string) $signal->ref->path();
                    }

                    return Behavior::same();
                }),
            ),
            'watcher',
        );

        // Stop the target first, then watch it after it is already dead.
        $runtime->scheduleOnce(Duration::millis(20), static fn() => $system->stop($target));
        $runtime->scheduleOnce(Duration::millis(80), static fn() => $watcher->tell(new WatchThat($target)));
        $runtime->scheduleOnce(Duration::millis(180), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertContains('/user/target', $terminatedPaths);
    }

    #[Test]
    public function unwatchStopsTerminatedDelivery(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('death-watch-unwatch', $runtime);

        $target = $system->spawn(self::plainProps(), 'target');

        /** @var list<string> $terminatedPaths */
        $terminatedPaths = [];

        $watcher = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof WatchThat) {
                        $ctx->watch($msg->target);
                    }

                    if ($msg instanceof UnwatchThat) {
                        $ctx->unwatch($msg->target);
                    }

                    return Behavior::same();
                })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$terminatedPaths): Behavior {
                    if ($signal instanceof Terminated) {
                        $terminatedPaths[] = (string) $signal->ref->path();
                    }

                    return Behavior::same();
                }),
            ),
            'watcher',
        );

        $runtime->scheduleOnce(Duration::millis(20), static fn() => $watcher->tell(new WatchThat($target)));
        $runtime->scheduleOnce(Duration::millis(40), static fn() => $watcher->tell(new UnwatchThat($target)));
        $runtime->scheduleOnce(Duration::millis(60), static fn() => $system->stop($target));
        $runtime->scheduleOnce(Duration::millis(160), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertNotContains('/user/target', $terminatedPaths);
    }

    #[Test]
    public function childNameIsReusableAfterChildStops(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('child-reuse', $runtime);

        /** @var list<string> $log */
        $log = [];
        $childRef = null;

        $parent = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$log, &$childRef): Behavior {
                    if ($msg instanceof SpawnChild) {
                        try {
                            $childRef = $ctx->spawn(self::plainChildProps(), 'c');
                            $log[] = 'spawned';
                        } catch (ActorNameExistsException) {
                            $log[] = 'name-exists';
                        }
                    }

                    if ($msg instanceof StopChild && $childRef !== null) {
                        $ctx->stop($childRef);
                    }

                    return Behavior::same();
                },
            )),
            'parent',
        );

        $runtime->scheduleOnce(Duration::millis(20), static fn() => $parent->tell(new SpawnChild()));
        $runtime->scheduleOnce(Duration::millis(60), static fn() => $parent->tell(new StopChild()));
        $runtime->scheduleOnce(Duration::millis(120), static fn() => $parent->tell(new SpawnChild()));
        $runtime->scheduleOnce(Duration::millis(220), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // Both spawns of name 'c' succeeded — the stopped child's name was reusable.
        self::assertSame(['spawned', 'spawned'], $log);
    }

    #[Test]
    public function parentChildLookupIsRemovedAfterChildStops(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('child-lookup', $runtime);

        /** @var list<bool> $lookups */
        $lookups = [];
        $childRef = null;

        $parent = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$lookups, &$childRef): Behavior {
                    if ($msg instanceof SpawnChild) {
                        $childRef = $ctx->spawn(self::plainChildProps(), 'c');
                    }

                    if ($msg instanceof StopChild && $childRef !== null) {
                        $ctx->stop($childRef);
                    }

                    if ($msg instanceof QueryChild) {
                        $lookups[] = $ctx->child('c') !== null;
                    }

                    return Behavior::same();
                },
            )),
            'parent',
        );

        $runtime->scheduleOnce(Duration::millis(20), static fn() => $parent->tell(new SpawnChild()));
        $runtime->scheduleOnce(Duration::millis(40), static fn() => $parent->tell(new QueryChild())); // true — alive
        $runtime->scheduleOnce(Duration::millis(60), static fn() => $parent->tell(new StopChild()));
        $runtime->scheduleOnce(Duration::millis(140), static fn() => $parent->tell(new QueryChild())); // false — gone
        $runtime->scheduleOnce(Duration::millis(240), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertSame([true, false], $lookups);
    }

    #[Test]
    public function stoppingAParentDeliversTerminatedForItsChildToAnExternalWatcher(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('nested-death-watch', $runtime);

        /** @var list<string> $terminatedPaths */
        $terminatedPaths = [];
        $childRef = null;

        // Parent spawns a child and hands its ref to the watcher.
        $watcher = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
                    if ($msg instanceof WatchThat) {
                        $ctx->watch($msg->target);
                    }

                    return Behavior::same();
                })->onSignal(static function (ActorContext $ctx, Signal $signal) use (&$terminatedPaths): Behavior {
                    if ($signal instanceof Terminated) {
                        $terminatedPaths[] = (string) $signal->ref->path();
                    }

                    return Behavior::same();
                }),
            ),
            'watcher',
        );

        $parent = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$childRef, $watcher): Behavior {
                    if ($msg instanceof SpawnChild) {
                        $childRef = $ctx->spawn(self::plainChildProps(), 'c');
                        $watcher->tell(new WatchThat($childRef));
                    }

                    return Behavior::same();
                },
            )),
            'parent',
        );

        $runtime->scheduleOnce(Duration::millis(20), static fn() => $parent->tell(new SpawnChild()));
        // Stopping the parent must recursively stop the child and fire its Terminated.
        $runtime->scheduleOnce(Duration::millis(80), static fn() => $system->stop($parent));
        $runtime->scheduleOnce(Duration::millis(200), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertContains('/user/parent/c', $terminatedPaths);
    }

    /** @return Props<object> */
    public static function plainChildProps(): Props
    {
        return Props::fromBehavior(Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        ));
    }

    private static function plainProps(): Props
    {
        return Props::fromBehavior(Behavior::receive(
            static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
        ));
    }
}

/** @internal */
final readonly class WatchThat
{
    /** @param ActorRef<object> $target */
    public function __construct(public ActorRef $target) {}
}

/** @internal */
final readonly class UnwatchThat
{
    /** @param ActorRef<object> $target */
    public function __construct(public ActorRef $target) {}
}

/** @internal */
final readonly class SpawnChild {}

/** @internal */
final readonly class StopChild {}

/** @internal */
final readonly class QueryChild {}
