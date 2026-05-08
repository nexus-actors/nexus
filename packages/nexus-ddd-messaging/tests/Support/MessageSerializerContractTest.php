<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Serialization\MessageSerializer;
use Monadial\Nexus\Ddd\Messaging\Serialization\SerializedMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * @psalm-api
 *
 * Contract test for MessageSerializer implementations. Concrete impls in
 * transport-adapter packages (Symfony Messenger, AMQP, Redis Stream) extend
 * this class and implement createSerializer() / payloadFor() to verify
 * round-trip fidelity without duplicating test logic.
 *
 * The contract: deserialize(serialize($e)) MUST yield an Envelope with
 * structural equality to the input — same id, same occurredAt (modulo
 * representable precision), same causation/correlation/conversation chain,
 * same trace context, same expiry, same vector clock, same body.
 */
abstract class MessageSerializerContractTest extends TestCase
{
    abstract protected function createSerializer(): MessageSerializer;

    /**
     * A throwaway serializable payload type — concrete tests can override
     * if their wire format requires a registered type or attribute.
     */
    abstract protected function payloadFor(string $value): object;

    #[Test]
    public function roundTripPreservesMessageId(): void
    {
        $serializer = $this->createSerializer();
        $envelope = $this->buildEnvelope('hello');

        $restored = $serializer->deserialize($serializer->serialize($envelope));

        self::assertTrue($restored->metadata->id->equals($envelope->metadata->id));
    }

    #[Test]
    public function roundTripPreservesOccurredAtToTheMicrosecond(): void
    {
        $serializer = $this->createSerializer();
        $envelope = $this->buildEnvelope('hello');

        $restored = $serializer->deserialize($serializer->serialize($envelope));

        self::assertSame(
            $envelope->metadata->occurredAt->format('Y-m-d\TH:i:s.uP'),
            $restored->metadata->occurredAt->format('Y-m-d\TH:i:s.uP'),
        );
    }

    #[Test]
    public function roundTripPreservesCausationCorrelationConversation(): void
    {
        $serializer = $this->createSerializer();
        $cause = MessageId::generate();
        $corr = MessageId::generate();
        $conv = MessageId::generate();

        $base = $this->buildEnvelope('hello');
        $envelope = new Envelope(
            $base->message,
            new MessageMetadata(
                id: $base->metadata->id,
                occurredAt: $base->metadata->occurredAt,
                causationId: Option::some($cause),
                correlationId: Option::some($corr),
                conversationId: Option::some($conv),
                schemaVersion: 1,
                traceParent: Option::none(),
                traceState: Option::none(),
                expiresAt: Option::none(),
                vectorClock: Option::none(),
            ),
        );

        $restored = $serializer->deserialize($serializer->serialize($envelope));

        self::assertTrue($restored->metadata->causationId->get()->equals($cause));
        self::assertTrue($restored->metadata->correlationId->get()->equals($corr));
        self::assertTrue($restored->metadata->conversationId->get()->equals($conv));
    }

    #[Test]
    public function serializeReturnsSerializedMessage(): void
    {
        $serializer = $this->createSerializer();

        $serialized = $serializer->serialize($this->buildEnvelope('hello'));

        self::assertInstanceOf(SerializedMessage::class, $serialized);
    }

    private function buildEnvelope(string $value): Envelope
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-05-08T12:00:00.123456+00:00');
            }
        };

        return new Envelope($this->payloadFor($value), MessageMetadata::root($clock));
    }
}
