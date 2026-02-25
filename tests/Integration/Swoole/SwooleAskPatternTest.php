<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Greet;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Greeted;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SwooleAskPatternTest extends TestCase
{
    #[Test]
    public function ask_returns_reply_from_actor(): void
    {
        $runtime = new SwooleRuntime();

        /** @var Greeted|null $result */
        $result = null;

        $system = ActorSystem::create('ask-test', $runtime);

        /** @var Behavior<object> $greeterBehavior */
        $greeterBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            if ($msg instanceof Greet) {
                $msg->replyTo->tell(new Greeted("Hello, {$msg->name}!"));
            }

            return Behavior::same();
        });

        $greeterRef = $system->spawn(Props::fromBehavior($greeterBehavior), 'greeter');

        // In Swoole, messages and ask() must happen inside coroutine context
        $runtime->scheduleOnce(Duration::millis(10), static function () use ($greeterRef, &$result): void {
            $result = $greeterRef->ask(
                static fn($replyTo) => new Greet('World', $replyTo),
                Duration::seconds(5),
            );
        });

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertInstanceOf(Greeted::class, $result);
        self::assertSame('Hello, World!', $result->greeting);
    }

    #[Test]
    public function ask_throws_timeout_when_no_reply(): void
    {
        $runtime = new SwooleRuntime();

        /** @var AskTimeoutException|null $caught */
        $caught = null;

        $system = ActorSystem::create('ask-timeout-test', $runtime);

        // Actor that ignores all messages
        /** @var Behavior<object> $blackHoleBehavior */
        $blackHoleBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        $blackHoleRef = $system->spawn(Props::fromBehavior($blackHoleBehavior), 'black-hole');

        $runtime->scheduleOnce(Duration::millis(10), static function () use ($blackHoleRef, &$caught): void {
            try {
                $blackHoleRef->ask(
                    static fn($replyTo) => new Greet('World', $replyTo),
                    Duration::millis(100),
                );
            } catch (AskTimeoutException $e) {
                $caught = $e;
            }
        });

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $runtime->run();

        self::assertInstanceOf(AskTimeoutException::class, $caught);
    }
}
