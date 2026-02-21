<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Router;

use Monadial\Nexus\Cluster\Router\SerializingRouter;
use Monadial\Nexus\Cluster\Serialization\PhpNativeClusterSerializer;
use Monadial\Nexus\Cluster\Transport\InMemoryTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(SerializingRouter::class)]
final class SerializingRouterTest extends TestCase
{
    #[Test]
    public function sendSerializesAndDelegatesToTransport(): void
    {
        $transport = new InMemoryTransport();
        $serializer = new PhpNativeClusterSerializer();
        $router = new SerializingRouter($transport, $serializer);

        $envelope = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/target'),
        );

        $router->send(5, $envelope);

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame(5, $sent[0]['targetWorker']);
        self::assertSame($serializer->serialize($envelope), $sent[0]['data']);
    }

    #[Test]
    public function startReceivingDeserializesIncomingMessages(): void
    {
        $transport = new InMemoryTransport();
        $serializer = new PhpNativeClusterSerializer();
        $router = new SerializingRouter($transport, $serializer);

        /** @var list<Envelope> $received */
        $received = [];

        $router->startReceiving(static function (Envelope $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $envelope = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/remote'),
            ActorPath::fromString('/user/local'),
        );

        $transport->receive($serializer->serialize($envelope));

        self::assertCount(1, $received);
        self::assertEquals($envelope, $received[0]);
    }

    #[Test]
    public function closeClosesTransport(): void
    {
        $transport = new InMemoryTransport();
        $serializer = new PhpNativeClusterSerializer();
        $router = new SerializingRouter($transport, $serializer);

        /** @var list<Envelope> $received */
        $received = [];

        $router->startReceiving(static function (Envelope $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        $router->close();

        // After close, transport listener is cleared — messages should not be received
        $envelope = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/target'),
        );

        $transport->receive($serializer->serialize($envelope));

        self::assertCount(0, $received);
    }
}
