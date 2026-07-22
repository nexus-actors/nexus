<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Message\Resume;
use Monadial\Nexus\Core\Message\Suspend;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REL-004: a Suspended actor still handles system messages (so Resume works), and
 * user messages queued while Suspended are replayed in order on Resume, not lost.
 */
final class SuspendResumeTest extends TestCase
{
    #[Test]
    public function suspendedActorDefersUserTrafficAndReplaysItInOrderOnResume(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('suspend-resume', $runtime);

        /** @var list<string> $processed */
        $processed = [];

        $ref = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$processed): Behavior {
                    if ($msg instanceof Note) {
                        $processed[] = $msg->text;
                    }

                    return Behavior::same();
                },
            )),
            'worker',
        );

        // Suspend, then send user traffic that must be deferred, then Resume.
        $ref->tell(new Suspend());
        $ref->tell(new Note('a'));
        $ref->tell(new Note('b'));

        // Snapshot mid-suspend: nothing processed yet.
        $duringSuspend = null;
        $runtime->scheduleOnce(Duration::millis(60), static function () use (&$duringSuspend, &$processed, $ref): void {
            $duringSuspend = $processed;
            $ref->tell(new Note('c'));
            $ref->tell(new Resume());
        });

        $runtime->scheduleOnce(Duration::millis(200), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertSame([], $duringSuspend, 'User messages must not be processed while Suspended');
        // All deferred messages replayed in arrival order — nothing lost, order preserved.
        self::assertSame(['a', 'b', 'c'], $processed);
    }

    #[Test]
    public function suspendedActorCanBeStopped(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('suspend-stop', $runtime);

        /** @var list<string> $signals */
        $signals = [];

        $ref = $system->spawn(
            Props::fromBehavior(
                Behavior::receive(
                    static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same(),
                )->onSignal(static function (ActorContext $ctx, object $signal) use (&$signals): Behavior {
                    $signals[] = $signal::class;

                    return Behavior::same();
                }),
            ),
            'worker',
        );

        $ref->tell(new Suspend());
        // A PoisonPill is a system message, so a Suspended actor still stops (PostStop fires).
        $runtime->scheduleOnce(Duration::millis(40), static fn() => $system->stop($ref));

        $runtime->scheduleOnce(Duration::millis(160), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertContains(PostStop::class, $signals);
        self::assertFalse($ref->isAlive());
    }
}

/** @internal */
final readonly class Note
{
    public function __construct(public string $text) {}
}
