<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Message\Resume;
use Monadial\Nexus\Core\Message\Suspend;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\TestCase;

/**
 * REL-004 cross-runtime: suspend/resume with deferred user traffic under Swoole.
 */
final class SwooleSuspendResumeTest extends TestCase
{
    public function testSuspendedActorDefersUserTrafficAndReplaysItInOrderOnResume(): void
    {
        $runtime = new SwooleRuntime();
        $system = ActorSystem::create('swoole-suspend-resume', $runtime);

        /** @var list<string> $processed */
        $processed = [];

        $ref = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$processed): Behavior {
                    if ($msg instanceof SwooleNote) {
                        $processed[] = $msg->text;
                    }

                    return Behavior::same();
                },
            )),
            'worker',
        );

        $duringSuspend = null;

        // All sends happen inside coroutine context (scheduleOnce callbacks).
        $runtime->scheduleOnce(Duration::millis(10), static function () use ($ref): void {
            $ref->tell(new Suspend());
            $ref->tell(new SwooleNote('a'));
            $ref->tell(new SwooleNote('b'));
        });
        $runtime->scheduleOnce(Duration::millis(70), static function () use (&$duringSuspend, &$processed, $ref): void {
            $duringSuspend = $processed;
            $ref->tell(new SwooleNote('c'));
            $ref->tell(new Resume());
        });
        $runtime->scheduleOnce(Duration::millis(220), static fn() => $system->shutdown(Duration::seconds(1)));

        $runtime->run();

        self::assertSame([], $duringSuspend, 'User messages must not be processed while Suspended');
        self::assertSame(['a', 'b', 'c'], $processed);
    }
}

/** @internal */
final readonly class SwooleNote
{
    public function __construct(public string $text) {}
}
