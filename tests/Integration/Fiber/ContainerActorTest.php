<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Fiber;

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
use Psr\Container\ContainerInterface;
use RuntimeException;

final class ContainerActorTest extends TestCase
{
    public function testFromContainerResolvesActorViaPsr11(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('container-test', $runtime);

        $captured = [];

        $handler = new class ($captured) implements ActorHandler {
            /** @param list<string> $captured */
            public function __construct(private array &$captured)
            {
            }

            public function handle(ActorContext $ctx, object $message): Behavior
            {
                if ($message instanceof Greet) {
                    $this->captured[] = $message->name;
                    $message->replyTo->tell(new Greeted("Hi, {$message->name}!"));
                }

                return Behavior::same();
            }
        };

        // Simple PSR-11 container stub
        $handlerClass = $handler::class;
        $container = new class ($handler, $handlerClass) implements ContainerInterface {
            public function __construct(private readonly object $handler, private readonly string $handlerClass)
            {
            }

            public function get(string $id): object
            {
                if ($id === $this->handlerClass) {
                    return $this->handler;
                }

                throw new RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === $this->handlerClass;
            }
        };

        $ref = $system->spawn(
            Props::fromContainer($container, $handler::class),
            'container-actor',
        );

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

        $ref->tell(new Greet('Container', $probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertSame(['Container'], $captured);
        self::assertSame(['Hi, Container!'], $replies);
    }
}
