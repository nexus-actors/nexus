<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\Route;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\OrderPlaced;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(MessengerBridge::class)]
final class ProducerConsumerLoopTest extends TestCase
{
    #[Test]
    public function fullLoopFromProducerThroughTransportToTargetActor(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('messenger-e2e', $runtime);
        $transport = new InMemoryTransport();

        $received = [];
        $target = $system->spawn(Props::fromBehavior(Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof OrderPlaced) {
                    $received[] = $msg->orderId;
                }

                return Behavior::same();
            },
        )), 'orders');

        $watchdog = $system->spawn(MessengerBridge::watchdogProps(
            $system,
            LifecycleThresholds::none()->withMessageLimit(3),
            Duration::millis(30),
            Duration::seconds(1),
        ), 'watchdog');

        $system->spawn(MessengerBridge::receiverProps(
            $transport,
            new MapMessageRouter(Route::to(OrderPlaced::class, $target)),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
            null,
            $watchdog,
        ), 'receiver');

        $producer = MessengerBridge::producer($transport, 'orders-out');
        $producer->tell(new OrderPlaced('A'));
        $producer->tell(new OrderPlaced('B'));
        $producer->tell(new OrderPlaced('C'));

        $safetyTriggered = false;
        $runtime->scheduleOnce(Duration::seconds(3), static function () use ($system, &$safetyTriggered): void {
            $safetyTriggered = true;
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame(['A', 'B', 'C'], $received);
        self::assertCount(3, $transport->getAcknowledged());
        self::assertFalse($safetyTriggered, 'watchdog message limit should have ended the run');
    }
}
