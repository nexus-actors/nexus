<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Platform\Bus;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Subscribe;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Bus\Publish;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextBusActor::class)]
final class ContextBusTest extends TestCase
{
    #[Test]
    public function publishedEventsFanOutToAllSubscribers(): void
    {
        $received = [];
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('bus-test', $runtime);

        $probe = static function (string $key) use (&$received): Behavior {
            return Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$received, $key): Behavior {
                $received[$key][] = $msg::class;

                return Behavior::same();
            });
        };

        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $a = $system->spawn(Props::fromBehavior($probe('a')), 'probe-a');
        $b = $system->spawn(Props::fromBehavior($probe('b')), 'probe-b');

        $bus->tell(new Subscribe($a));
        $bus->tell(new Subscribe($b));
        $bus->tell(new Publish(new FakeEvent()));

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame([FakeEvent::class], $received['a'] ?? []);
        self::assertSame([FakeEvent::class], $received['b'] ?? []);
    }
}

final readonly class FakeEvent {}
