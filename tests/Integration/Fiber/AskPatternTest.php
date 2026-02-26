<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Greeted;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final readonly class GreetRequest
{
    public function __construct(public string $name) {}
}

final class AskPatternTest extends TestCase
{
    #[Test]
    public function ask_returns_reply_from_actor(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-test', $runtime);

        /** @var Behavior<object> $greeterBehavior */
        $greeterBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            if ($msg instanceof GreetRequest) {
                $ctx->reply(new Greeted("Hello, {$msg->name}!"));
            }

            return Behavior::same();
        });

        $greeterRef = $system->spawn(Props::fromBehavior($greeterBehavior), 'greeter');

        /** @var Greeted|null $result */
        $result = null;

        // ask() must be called from within a fiber context
        $runtime->spawn(static function () use ($greeterRef, &$result): void {
            $result = $greeterRef->ask(new GreetRequest('World'), Duration::seconds(5))->await();
        });

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertInstanceOf(Greeted::class, $result);
        self::assertSame('Hello, World!', $result->greeting);
    }

    #[Test]
    public function ask_throws_timeout_when_no_reply(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ask-timeout-test', $runtime);

        // Actor that ignores all messages
        /** @var Behavior<object> $blackHoleBehavior */
        $blackHoleBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg): Behavior {
            return Behavior::same();
        });

        $blackHoleRef = $system->spawn(Props::fromBehavior($blackHoleBehavior), 'black-hole');

        /** @var AskTimeoutException|null $caught */
        $caught = null;

        $runtime->spawn(static function () use ($blackHoleRef, &$caught): void {
            try {
                $blackHoleRef->ask(new GreetRequest('World'), Duration::millis(100))->await();
            } catch (AskTimeoutException $e) {
                $caught = $e;
            }
        });

        $runtime->scheduleOnce(Duration::millis(500), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertInstanceOf(AskTimeoutException::class, $caught);
    }
}
