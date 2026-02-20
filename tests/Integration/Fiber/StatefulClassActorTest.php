<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\CountReply;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\GetCount;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Increment;
use PHPUnit\Framework\TestCase;

final class StatefulClassActorTest extends TestCase
{
    public function testStatefulActorHandlerManagesState(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('stateful-class-test', $runtime);

        $counter = new class implements StatefulActorHandler {
            public function initialState(): int
            {
                return 0;
            }

            public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
            {
                if ($message instanceof Increment) {
                    return BehaviorWithState::next($state + 1);
                }

                if ($message instanceof GetCount) {
                    $message->replyTo->tell(new CountReply($state));

                    return BehaviorWithState::same();
                }

                return BehaviorWithState::same();
            }
        };

        $counterRef = $system->spawn(
            Props::fromStatefulFactory(static fn() => $counter),
            'counter',
        );

        // Send 5 increments
        for ($i = 0; $i < 5; $i++) {
            $counterRef->tell(new Increment());
        }

        // Probe to capture reply
        $reply = null;
        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$reply): Behavior {
                if ($msg instanceof CountReply) {
                    $reply = $msg->count;
                }

                return Behavior::same();
            },
        );
        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        $counterRef->tell(new GetCount($probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertSame(5, $reply);
    }
}
