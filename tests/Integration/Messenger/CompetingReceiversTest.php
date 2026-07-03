<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\OrderPlaced;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

use function sort;

#[CoversClass(MessengerBridge::class)]
final class CompetingReceiversTest extends TestCase
{
    #[Test]
    public function multipleReceiversDrainOneTransportWithoutLossOrRejects(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('competing-receivers', $runtime);
        $transport = new InMemoryTransport();

        foreach (['a', 'b', 'c', 'd'] as $id) {
            $transport->send(new Envelope(new OrderPlaced($id)));
        }

        $received = [];
        $target = $system->spawn(Props::fromBehavior(Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof OrderPlaced) {
                    $received[] = $msg->orderId;
                }

                return Behavior::same();
            },
        )), 'orders');

        $refs = MessengerBridge::spawnReceivers(
            $system,
            3,
            'receiver',
            $transport,
            new MapMessageRouter([OrderPlaced::class => $target]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
        );

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        sort($received);

        self::assertCount(3, $refs);
        self::assertSame(['a', 'b', 'c', 'd'], $received);
        self::assertCount(4, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }
}
