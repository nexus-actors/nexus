<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Step;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Monadial\Nexus\Tests\Integration\Step\Messages\CountReply;
use Monadial\Nexus\Tests\Integration\Step\Messages\GetCount;
use Monadial\Nexus\Tests\Integration\Step\Messages\Increment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class DoAsk
{
    // Marker message to trigger an ask from within actor context
}

final class StepAskPatternTest extends TestCase
{
    private StepRuntime $runtime;
    private ActorSystem $system;

    #[Test]
    public function ask_returns_reply_via_step(): void
    {
        /** @var Behavior<object> $counterBehavior */
        $counterBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                if ($msg instanceof Increment) {
                    return BehaviorWithState::next($count + 1);
                }

                if ($msg instanceof GetCount) {
                    $msg->replyTo->tell(new CountReply($count));

                    return BehaviorWithState::same();
                }

                return BehaviorWithState::same();
            },
        );

        $counterRef = $this->system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        // Send 3 increments
        for ($i = 0; $i < 3; $i++) {
            $counterRef->tell(new Increment());
        }

        // Process the 3 increments
        for ($i = 0; $i < 3; $i++) {
            $this->runtime->step();
        }

        /** @var CountReply|null $result */
        $result = null;

        // Spawn an "asker" actor that uses ask() to get the count
        /** @var Behavior<object> $askerBehavior */
        $askerBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use ($counterRef, &$result): Behavior {
                if ($msg instanceof DoAsk) {
                    $result = $counterRef->ask(
                        static fn($replyTo) => new GetCount($replyTo),
                        Duration::seconds(5),
                    );
                }

                return Behavior::same();
            },
        );

        $askerRef = $this->system->spawn(Props::fromBehavior($askerBehavior), 'asker');
        $askerRef->tell(new DoAsk());

        // Drain: asker processes DoAsk → asks counter → counter replies → asker gets reply
        $this->runtime->drain();

        self::assertInstanceOf(CountReply::class, $result);
        self::assertSame(3, $result->count);
    }

    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create('step-ask-test', $this->runtime, clock: $this->runtime->clock());
    }
}
