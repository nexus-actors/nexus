<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Router;

use Monadial\Nexus\Cluster\Router\DirectRouter;
use Monadial\Nexus\Cluster\Transport\InMemoryEnvelopeTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(DirectRouter::class)]
final class DirectRouterTest extends TestCase
{
    #[Test]
    public function sendDelegatesToEnvelopeTransport(): void
    {
        $transport = new InMemoryEnvelopeTransport();
        $router = new DirectRouter($transport);

        $envelope = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/target'),
        );

        $router->send(3, $envelope);

        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        self::assertSame(3, $sent[0]['targetWorker']);
        self::assertSame($envelope, $sent[0]['envelope']);
    }

    #[Test]
    public function startReceivingCallsHandlerWithReceivedEnvelope(): void
    {
        $transport = new InMemoryEnvelopeTransport();
        $router = new DirectRouter($transport);

        $envelope = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/remote'),
            ActorPath::fromString('/user/local'),
        );

        $transport->deliver($envelope);

        /** @var list<Envelope> $received */
        $received = [];

        // InMemoryEnvelopeTransport.receive() throws RuntimeException when inbox is empty,
        // which breaks the loop after processing the delivered envelope.
        $router->startReceiving(static function (Envelope $envelope) use (&$received): void {
            $received[] = $envelope;
        });

        self::assertCount(1, $received);
        self::assertSame($envelope, $received[0]);
    }
}
