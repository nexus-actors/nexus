<?php
declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tests\Unit\Serialization;

use Monadial\Nexus\Cluster\Serialization\CompactClusterSerializer;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Mailbox\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CompactClusterSerializer::class)]
final class CompactClusterSerializerTest extends TestCase
{
    private CompactClusterSerializer $serializer;

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
    }

    #[Test]
    public function compactFormatIsSmallerThanPhpNative(): void
    {
        $envelope = Envelope::of(
            (object) ['seq' => 42],
            ActorPath::root(),
            ActorPath::fromString('/user/actor-7'),
        );

        $compact = $this->serializer->serialize($envelope);
        $native = serialize($envelope);

        self::assertLessThan(strlen($native), strlen($compact));
    }

    #[Test]
    public function throwsOnTruncatedData(): void
    {
        $this->expectException(RuntimeException::class);
        (void) $this->serializer->deserialize('ab');
    }

    #[Test]
    public function roundtripWithStdClassMessage(): void
    {
        $envelope = Envelope::of(
            (object) ['payload' => 'test', 'count' => 99],
            ActorPath::root(),
            ActorPath::fromString('/user/sink'),
        );

        $restored = $this->serializer->deserialize($this->serializer->serialize($envelope));

        self::assertSame('test', $restored->message->payload);
        self::assertSame(99, $restored->message->count);
        self::assertSame('/', (string) $restored->sender);
        self::assertSame('/user/sink', (string) $restored->target);
    }

    protected function setUp(): void
    {
        $this->serializer = new CompactClusterSerializer();
    }
}
