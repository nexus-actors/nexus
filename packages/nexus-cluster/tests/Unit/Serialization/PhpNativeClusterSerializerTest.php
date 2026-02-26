<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Serialization;

use Monadial\Nexus\Cluster\Serialization\PhpNativeClusterSerializer;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpNativeClusterSerializer::class)]
final class PhpNativeClusterSerializerTest extends TestCase
{
    private PhpNativeClusterSerializer $serializer;

    #[Test]
    public function roundtripPreservesMessage(): void
    {
        $envelope = Envelope::of(
            new TestClusterMessage('hello', 42),
            ActorPath::fromString('/user/sender'),
            ActorPath::fromString('/user/target'),
        );

        $data = $this->serializer->serialize($envelope);
        $restored = $this->serializer->deserialize($data);

        self::assertInstanceOf(TestClusterMessage::class, $restored->message);
        self::assertSame('hello', $restored->message->text);
        self::assertSame(42, $restored->message->value);
    }

    #[Test]
    public function roundtripPreservesPaths(): void
    {
        $sender = ActorPath::fromString('/user/actor-a');
        $target = ActorPath::fromString('/user/actor-b');
        $envelope = Envelope::of(new TestClusterMessage('x', 1), $sender, $target);

        $restored = $this->serializer->deserialize($this->serializer->serialize($envelope));

        self::assertSame('/user/actor-a', (string) $restored->sender);
        self::assertSame('/user/actor-b', (string) $restored->target);
        self::assertSame($envelope->requestId, $restored->requestId);
        self::assertSame($envelope->correlationId, $restored->correlationId);
        self::assertSame($envelope->causationId, $restored->causationId);
    }

    #[Test]
    public function roundtripPreservesMetadata(): void
    {
        $envelope = new Envelope(
            message: new TestClusterMessage('x', 1),
            sender: ActorPath::fromString('/sender'),
            target: ActorPath::fromString('/target'),
            requestId: 'request-1',
            correlationId: 'correlation-1',
            causationId: 'causation-1',
            metadata: ['trace-id' => 'abc-123', 'priority' => 'high'],
        );

        $restored = $this->serializer->deserialize($this->serializer->serialize($envelope));

        self::assertSame('abc-123', $restored->metadata['trace-id']);
        self::assertSame('high', $restored->metadata['priority']);
        self::assertSame('request-1', $restored->requestId);
        self::assertSame('correlation-1', $restored->correlationId);
        self::assertSame('causation-1', $restored->causationId);
    }

    protected function setUp(): void
    {
        $this->serializer = new PhpNativeClusterSerializer();
    }
}
