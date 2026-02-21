<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Transport;

use Monadial\Nexus\Cluster\Transport\InMemoryEnvelopeTransport;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(InMemoryEnvelopeTransport::class)]
final class InMemoryEnvelopeTransportTest extends TestCase
{
    #[Test]
    public function sendRecordsEnvelopes(): void
    {
        $transport = new InMemoryEnvelopeTransport();

        $envelope1 = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/target-a'),
        );
        $envelope2 = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/target-b'),
        );

        $transport->send(3, $envelope1);
        $transport->send(7, $envelope2);

        $sent = $transport->getSent();
        self::assertCount(2, $sent);
        self::assertSame(3, $sent[0]['targetWorker']);
        self::assertSame($envelope1, $sent[0]['envelope']);
        self::assertSame(7, $sent[1]['targetWorker']);
        self::assertSame($envelope2, $sent[1]['envelope']);
    }

    #[Test]
    public function receiveReturnsDeliveredEnvelope(): void
    {
        $transport = new InMemoryEnvelopeTransport();

        $envelope = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/remote'),
            ActorPath::fromString('/user/local'),
        );

        $transport->deliver($envelope);
        $received = $transport->receive();

        self::assertSame($envelope, $received);
    }

    #[Test]
    public function receiveThrowsWhenInboxIsEmpty(): void
    {
        $transport = new InMemoryEnvelopeTransport();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No envelopes available in inbox');

        /** @psalm-suppress UnrecognizedExpression */
        (void) $transport->receive();
    }

    #[Test]
    public function getSentToFiltersByWorker(): void
    {
        $transport = new InMemoryEnvelopeTransport();

        $envelope1 = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/a'),
        );
        $envelope2 = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/b'),
        );
        $envelope3 = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/c'),
        );

        $transport->send(1, $envelope1);
        $transport->send(2, $envelope2);
        $transport->send(1, $envelope3);

        self::assertSame([$envelope1, $envelope3], $transport->getSentTo(1));
        self::assertSame([$envelope2], $transport->getSentTo(2));
        self::assertSame([], $transport->getSentTo(99));
    }

    #[Test]
    public function closeClearsState(): void
    {
        $transport = new InMemoryEnvelopeTransport();

        $envelope = Envelope::of(
            new stdClass(),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/target'),
        );

        $transport->send(1, $envelope);
        $transport->deliver($envelope);

        $transport->close();

        self::assertSame([], $transport->getSent());

        $this->expectException(RuntimeException::class);

        /** @psalm-suppress UnrecognizedExpression */
        (void) $transport->receive();
    }
}
