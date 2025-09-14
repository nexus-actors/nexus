<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Greet;
use Monadial\Nexus\Tests\Integration\Fiber\Messages\Greeted;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ClassBasedActorTest extends TestCase
{
    public function testActorHandlerReceivesMessages(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('handler-test', $runtime);

        $captured = [];

        $handler = new class ($captured) implements ActorHandler {
            /** @param list<string> $captured */
            public function __construct(private array &$captured) {}

            public function handle(ActorContext $ctx, object $message): Behavior
            {
                if ($message instanceof Greet) {
                    $this->captured[] = $message->name;
                    $message->replyTo->tell(new Greeted("Hello, {$message->name}!"));
                }

                return Behavior::same();
            }
        };

        $greeterRef = $system->spawn(Props::fromFactory(static fn () => $handler), 'greeter');

        // Probe to capture replies
        $replies = [];
        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$replies): Behavior {
                if ($msg instanceof Greeted) {
                    $replies[] = $msg->greeting;
                }

                return Behavior::same();
            },
        );
        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        $greeterRef->tell(new Greet('Alice', $probeRef));
        $greeterRef->tell(new Greet('Bob', $probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertSame(['Alice', 'Bob'], $captured);
        self::assertSame(['Hello, Alice!', 'Hello, Bob!'], $replies);
    }

    public function testAbstractActorLifecycleHooks(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('lifecycle-test', $runtime);

        $events = [];

        $actor = new class ($events) extends AbstractActor {
            /** @param list<string> $events */
            public function __construct(private array &$events) {}

            public function onPreStart(ActorContext $ctx): void
            {
                $this->events[] = 'preStart';
            }

            public function handle(ActorContext $ctx, object $message): Behavior
            {
                $this->events[] = 'handle';

                return Behavior::same();
            }

            public function onPostStop(ActorContext $ctx): void
            {
                $this->events[] = 'postStop';
            }
        };

        $ref = $system->spawn(Props::fromFactory(static fn () => $actor), 'lifecycle-actor');
        $ref->tell(new stdClass());

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertSame('preStart', $events[0]);
        self::assertContains('handle', $events);
        self::assertContains('postStop', $events);
    }
}
