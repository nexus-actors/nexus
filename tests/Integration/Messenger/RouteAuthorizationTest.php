<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActor;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Routing\MapTargetAuthorizer;
use Monadial\Nexus\Messenger\Routing\StampMessageRouter;
use Monadial\Nexus\Messenger\Stamp\ProducerIdentityStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Ping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * SEC-012: producer → target routing through StampMessageRouter must be authorized
 * per envelope, so a producer with publish rights cannot invoke every registered
 * target or consume its capacity.
 */
#[CoversClass(StampMessageRouter::class)]
final class RouteAuthorizationTest extends TestCase
{
    #[Test]
    public function authorizedProducerReachesTargetWhileUnauthorizedIsDeniedAndIsolated(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('route-authz', $runtime);
        $transport = new InMemoryTransport();

        $received = [];
        $target = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                    if ($msg instanceof Ping) {
                        $received[] = $msg->id;
                    }

                    return Behavior::same();
                },
            )),
            'orders',
        );

        $targetPath = (string) $target->path();

        // The authorized producer may reach the orders target; the rogue one may not.
        $router = new StampMessageRouter(
            [$targetPath => $target],
            new MapTargetAuthorizer(['orders-svc' => [$targetPath]]),
        );

        // Authorized first, then the rogue publisher aims at the same target.
        $transport->send(
            (new Envelope(new Ping('authorized')))
                ->with(new TargetActorPathStamp($targetPath))
                ->with(new ProducerIdentityStamp('orders-svc')),
        );
        $transport->send(
            (new Envelope(new Ping('rogue')))
                ->with(new TargetActorPathStamp($targetPath))
                ->with(new ProducerIdentityStamp('rogue-svc')),
        );

        $system->spawn(
            Props::fromBehavior(ReceiverActor::create(
                $transport,
                $router,
                ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
            )),
            'receiver',
        );

        $runtime->scheduleOnce(
            Duration::millis(300),
            static function () use ($system): void {
                $system->shutdown(Duration::seconds(1));
            },
        );
        $system->run();

        // Only the authorized message reached the target — capacity isolation holds.
        self::assertSame(['authorized'], $received);
        // The authorized envelope was acked; the rogue one was rejected back to the transport.
        self::assertCount(1, $transport->getAcknowledged());
        self::assertGreaterThanOrEqual(1, count($transport->getRejected()));
    }
}
