<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Tests\Unit\Payload;

use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\Msgpack\MsgpackCodec;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessagePayloadCodec::class)]
final class MessagePayloadCodecTest extends TestCase
{
    private MessagePayloadCodec $codec;

    #[Test]
    public function roundTripsAFullyPopulatedEnvelope(): void
    {
        $payload = new MessagePayload(
            targetPath: '/user/orders',
            messageType: 'app.order-created',
            body: "\x82\xA2id\x01\xA4name\xA3foo",
            correlationId: 'abc123',
            replyPath: '/cluster/prod/eu/app/node-1/ask/abc123',
            trace: ['traceparent' => '00-aaaa-bbbb-01', 'tracestate' => 'nexus=1'],
        );

        $decoded = $this->codec->unpack($this->codec->pack($payload));

        self::assertSame($payload->targetPath, $decoded->targetPath);
        self::assertSame($payload->messageType, $decoded->messageType);
        self::assertSame($payload->body, $decoded->body);
        self::assertSame($payload->correlationId, $decoded->correlationId);
        self::assertSame($payload->replyPath, $decoded->replyPath);
        self::assertSame($payload->trace, $decoded->trace);
    }

    #[Test]
    public function roundTripsNullsAndEmptyTrace(): void
    {
        $payload = new MessagePayload(
            targetPath: '/user/sink',
            messageType: 'test.ping',
            body: 'raw',
            correlationId: null,
            replyPath: null,
            trace: [],
        );

        $decoded = $this->codec->unpack($this->codec->pack($payload));

        self::assertNull($decoded->correlationId);
        self::assertNull($decoded->replyPath);
        self::assertSame([], $decoded->trace);
    }

    #[Test]
    public function roundTripsBinaryBodyBytes(): void
    {
        // Non-UTF8 bytes, as real msgpack-serialized bodies are.
        $body = "\x00\x01\xFF\xFE" . random_bytes(64);

        $payload = new MessagePayload('/user/a', 'test.ping', $body, null, null, []);

        self::assertSame($body, $this->codec->unpack($this->codec->pack($payload))->body);
    }

    /**
     * Wire interop, direction 1: an envelope packed by this codec must decode on a
     * node still running the generic Valinor-backed serializer (old receiver).
     */
    #[Test]
    public function genericSerializerDecodesFastCodecOutput(): void
    {
        $payload = new MessagePayload(
            targetPath: '/user/orders',
            messageType: 'app.order-created',
            body: 'body-bytes',
            correlationId: 'cid',
            replyPath: '/reply',
            trace: ['traceparent' => '00-aa-bb-01'],
        );

        $decoded = $this->genericSerializer()->deserialize($this->codec->pack($payload), 'cluster.message');

        self::assertInstanceOf(MessagePayload::class, $decoded);
        self::assertSame($payload->targetPath, $decoded->targetPath);
        self::assertSame($payload->messageType, $decoded->messageType);
        self::assertSame($payload->body, $decoded->body);
        self::assertSame($payload->correlationId, $decoded->correlationId);
        self::assertSame($payload->replyPath, $decoded->replyPath);
        self::assertSame($payload->trace, $decoded->trace);
    }

    /**
     * Wire interop, direction 2: an envelope serialized by the generic serializer
     * (old sender) must decode through this codec (new receiver).
     */
    #[Test]
    public function fastCodecDecodesGenericSerializerOutput(): void
    {
        $payload = new MessagePayload(
            targetPath: '/user/orders',
            messageType: 'app.order-created',
            body: 'body-bytes',
            correlationId: null,
            replyPath: null,
            trace: [],
        );

        $decoded = $this->codec->unpack($this->genericSerializer()->serialize($payload));

        self::assertSame($payload->targetPath, $decoded->targetPath);
        self::assertSame($payload->messageType, $decoded->messageType);
        self::assertSame($payload->body, $decoded->body);
        self::assertNull($decoded->correlationId);
        self::assertNull($decoded->replyPath);
        self::assertSame([], $decoded->trace);
    }

    #[Test]
    public function rejectsGarbageBytes(): void
    {
        $this->expectException(MessageDeserializationException::class);

        $this->codec->unpack("\xFF\xFF\xFF not msgpack");
    }

    #[Test]
    public function rejectsNonMapPayload(): void
    {
        $this->expectException(MessageDeserializationException::class);

        // A packed scalar is valid msgpack but not an envelope map.
        $this->codec->unpack(new MsgpackCodec()->pack(['just', 'a', 'list']));
    }

    #[Test]
    public function rejectsEnvelopeMissingRequiredField(): void
    {
        $this->expectException(MessageDeserializationException::class);
        $this->expectExceptionMessage("Field 'body'");

        // No 'body'.
        $this->codec->unpack(new MsgpackCodec()->pack([
            'messageType' => 'test.ping',
            'targetPath' => '/user/a',
        ]));
    }

    #[Test]
    public function rejectsWrongFieldType(): void
    {
        $this->expectException(MessageDeserializationException::class);

        $this->codec->unpack(new MsgpackCodec()->pack([
            'body' => 12345, // must be string
            'messageType' => 'test.ping',
            'targetPath' => '/user/a',
        ]));
    }

    #[Test]
    public function rejectsNonStringCorrelationId(): void
    {
        $this->expectException(MessageDeserializationException::class);
        $this->expectExceptionMessage('correlationId');

        $this->codec->unpack(new MsgpackCodec()->pack([
            'body' => 'b',
            'correlationId' => 42,
            'messageType' => 'test.ping',
            'targetPath' => '/user/a',
        ]));
    }

    #[Test]
    public function rejectsNonStringTraceEntries(): void
    {
        $this->expectException(MessageDeserializationException::class);
        $this->expectExceptionMessage('trace');

        $this->codec->unpack(new MsgpackCodec()->pack([
            'body' => 'b',
            'messageType' => 'test.ping',
            'targetPath' => '/user/a',
            'trace' => ['traceparent' => 123],
        ]));
    }

    protected function setUp(): void
    {
        $this->codec = new MessagePayloadCodec();
    }

    private function genericSerializer(): MessagePackMessageSerializer
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(MessagePayload::class);

        return new MessagePackMessageSerializer($registry);
    }
}
