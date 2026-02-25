<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Step;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Monadial\Nexus\Tests\Integration\Step\Messages\CountReply;
use Monadial\Nexus\Tests\Integration\Step\Messages\Increment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class AskGetCount
{
    // ask-pattern request message (no replyTo field needed)
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

                if ($msg instanceof AskGetCount) {
                    $ctx->reply(new CountReply($count));

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

        /** @var Future<CountReply> $future */
        $future = $counterRef->ask(new AskGetCount(), Duration::seconds(5));

        // Drain: counter processes ask request and resolves the future
        $this->runtime->drain();

        /** @var CountReply $result */
        $result = $future->await();
        self::assertInstanceOf(CountReply::class, $result);
        self::assertSame(3, $result->count);
    }

    protected function setUp(): void
    {
        $this->runtime = new StepRuntime();
        $this->system = ActorSystem::create('step-ask-test', $this->runtime, clock: $this->runtime->clock());
    }
}
