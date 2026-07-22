<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Routing;

use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Messenger\Routing\MapTargetAuthorizer;
use Monadial\Nexus\Messenger\Routing\StampMessageRouter;
use Monadial\Nexus\Messenger\Stamp\ProducerIdentityStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(StampMessageRouter::class)]
final class StampMessageRouterTest extends TestCase
{
    #[Test]
    public function routesByTargetPathStamp(): void
    {
        $target = new DeadLetterRef();
        $router = new StampMessageRouter(['/user/orders' => $target]);
        $message = new stdClass();
        $envelope = new Envelope($message, [new TargetActorPathStamp('/user/orders')]);

        self::assertSame($target, $router->route($message, $envelope));
    }

    #[Test]
    public function returnsNullWhenStampIsMissing(): void
    {
        $router = new StampMessageRouter(['/user/orders' => new DeadLetterRef()]);
        $message = new stdClass();

        self::assertNull($router->route($message, new Envelope($message)));
    }

    #[Test]
    public function returnsNullWhenPathIsUnknown(): void
    {
        $router = new StampMessageRouter([]);
        $message = new stdClass();
        $envelope = new Envelope($message, [new TargetActorPathStamp('/user/unknown')]);

        self::assertNull($router->route($message, $envelope));
    }

    #[Test]
    public function routesWhenTheAuthorizerPermitsTheProducerToReachTheTarget(): void
    {
        $target = new DeadLetterRef();
        $router = new StampMessageRouter(
            ['/user/orders' => $target],
            new MapTargetAuthorizer(['orders-svc' => ['/user/orders']]),
        );
        $message = new stdClass();
        $envelope = new Envelope($message, [
            new TargetActorPathStamp('/user/orders'),
            new ProducerIdentityStamp('orders-svc'),
        ]);

        self::assertSame($target, $router->route($message, $envelope));
    }

    #[Test]
    public function deniesRoutingWhenTheAuthorizerRejectsTheProducer(): void
    {
        $router = new StampMessageRouter(
            ['/user/orders' => new DeadLetterRef()],
            new MapTargetAuthorizer(['orders-svc' => ['/user/orders']]),
        );
        $message = new stdClass();

        // A registered target exists, but this producer is not allowed to reach it.
        $envelope = new Envelope($message, [
            new TargetActorPathStamp('/user/orders'),
            new ProducerIdentityStamp('rogue-svc'),
        ]);

        self::assertNull($router->route($message, $envelope));
    }

    #[Test]
    public function deniesRoutingWhenNoProducerIdentityIsPresentAndAnAuthorizerIsConfigured(): void
    {
        $router = new StampMessageRouter(
            ['/user/orders' => new DeadLetterRef()],
            new MapTargetAuthorizer(['orders-svc' => ['/user/orders']]),
        );
        $message = new stdClass();
        $envelope = new Envelope($message, [new TargetActorPathStamp('/user/orders')]);

        self::assertNull($router->route($message, $envelope));
    }
}
