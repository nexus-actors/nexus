<?php

declare(strict_types=1);

namespace Monadial\Nexus\Serialization\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Serialization\DefaultEnvelopeSerializer;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
use NoDiscard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function serialize;

use Throwable;

use function unserialize;

#[CoversClass(DefaultEnvelopeSerializer::class)]
final class DefaultEnvelopeSerializerTest extends TestCase
{
    #[Test]
    public function roundtripPreservesEnvelope(): void
    {
        $messageSerializer = new FakeMessageSerializer();
        $serializer = new DefaultEnvelopeSerializer($messageSerializer);

        $message = new EnvelopeTestMessage('hello', 42);
        $sender = ActorPath::fromString('/user/sender');
        $target = ActorPath::fromString('/user/target');
        $envelope = Envelope::of($message, $sender, $target);

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertInstanceOf(EnvelopeTestMessage::class, $restored->message);
        self::assertSame('hello', $restored->message->text);
        self::assertSame(42, $restored->message->number);
        self::assertTrue($restored->sender->equals($sender));
        self::assertTrue($restored->target->equals($target));
    }

    #[Test]
    public function preservesSenderAndTargetPaths(): void
    {
        $messageSerializer = new FakeMessageSerializer();
        $serializer = new DefaultEnvelopeSerializer($messageSerializer);

        $sender = ActorPath::fromString('/system/guardian');
        $target = ActorPath::fromString('/user/orders/order-123');
        $envelope = Envelope::of(new EnvelopeTestMessage('test', 1), $sender, $target);

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertSame('/system/guardian', (string) $restored->sender);
        self::assertSame('/user/orders/order-123', (string) $restored->target);
    }

    #[Test]
    public function preservesMetadata(): void
    {
        $messageSerializer = new FakeMessageSerializer();
        $serializer = new DefaultEnvelopeSerializer($messageSerializer);

        $sender = ActorPath::fromString('/sender');
        $target = ActorPath::fromString('/target');
        $envelope = new Envelope(
            new EnvelopeTestMessage('test', 1),
            $sender,
            $target,
            ['trace-id' => 'abc-123', 'request-id' => 'req-456'],
        );

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertSame('abc-123', $restored->metadata['trace-id']);
        self::assertSame('req-456', $restored->metadata['request-id']);
    }

    #[Test]
    public function preservesRootPaths(): void
    {
        $messageSerializer = new FakeMessageSerializer();
        $serializer = new DefaultEnvelopeSerializer($messageSerializer);

        $envelope = Envelope::of(
            new EnvelopeTestMessage('test', 1),
            ActorPath::root(),
            ActorPath::root(),
        );

        $data = $serializer->serialize($envelope);
        $restored = $serializer->deserialize($data);

        self::assertSame('/', (string) $restored->sender);
        self::assertSame('/', (string) $restored->target);
    }
}

final readonly class EnvelopeTestMessage
{
    public function __construct(public string $text, public int $number) {}
}

/**
 * A fake MessageSerializer for testing that uses PHP's native serialize/unserialize.
 */
final readonly class FakeMessageSerializer implements MessageSerializer
{
    /**
     * @throws MessageSerializationException
     */
    #[NoDiscard]
    public function serialize(object $message): string
    {
        try {
            return \serialize($message);
        } catch (Throwable $e) {
            throw new MessageSerializationException(
                $message::class,
                $e->getMessage(),
                $e,
            );
        }
    }

    /**
     * @throws MessageDeserializationException
     */
    #[NoDiscard]
    public function deserialize(string $data, string $type): object
    {
        try {
            $result = \unserialize($data);
        } catch (Throwable $e) {
            throw new MessageDeserializationException($type, $e->getMessage(), $e);
        }

        if (!is_object($result)) {
            throw new MessageDeserializationException($type, 'Unserialized data is not an object');
        }

        return $result;
    }
}
