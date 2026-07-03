<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Serialization;

use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TraceContextStamp;
use Monadial\Nexus\Messenger\Tests\Unit\Fixture\Greeting;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

#[CoversClass(NexusMessengerSerializer::class)]
final class NexusMessengerSerializerTest extends TestCase
{
    private NexusMessengerSerializer $serializer;

    #[Test]
    public function encodeProducesBodyAndRegisteredTypeHeader(): void
    {
        $encoded = $this->serializer->encode(new Envelope(new Greeting('hello')));

        self::assertArrayHasKey('body', $encoded);
        self::assertSame('greeting', $encoded['headers']['type']);
    }

    #[Test]
    public function roundTripPreservesMessageAndBridgeStamps(): void
    {
        $envelope = new Envelope(new Greeting('hello'), [
            new SourceActorPathStamp('/user/emitter'),
            new TargetActorPathStamp('/user/orders'),
        ]);

        $decoded = $this->serializer->decode($this->serializer->encode($envelope));
        $message = $decoded->getMessage();

        self::assertInstanceOf(Greeting::class, $message);
        self::assertSame('hello', $message->text);

        $source = $decoded->last(SourceActorPathStamp::class);
        $target = $decoded->last(TargetActorPathStamp::class);

        self::assertInstanceOf(SourceActorPathStamp::class, $source);
        self::assertSame('/user/emitter', $source->path);
        self::assertInstanceOf(TargetActorPathStamp::class, $target);
        self::assertSame('/user/orders', $target->path);
    }

    #[Test]
    public function encodeFallsBackToFqcnWhenTypeIsUnregistered(): void
    {
        $serializer = new NexusMessengerSerializer(
            new PhpNativeSerializer([Greeting::class]),
            new TypeRegistry(),
        );

        $encoded = $serializer->encode(new Envelope(new Greeting('hello')));

        self::assertSame(Greeting::class, $encoded['headers']['type']);
        self::assertInstanceOf(Greeting::class, $serializer->decode($encoded)->getMessage());
    }

    #[Test]
    public function decodeRejectsMissingTypeHeader(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => 'x', 'headers' => []]);
    }

    #[Test]
    public function decodeRejectsUnknownType(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => 'x', 'headers' => ['type' => 'no-such-type']]);
    }

    #[Test]
    public function decodeRejectsMissingBody(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['headers' => ['type' => 'greeting']]);
    }

    #[Test]
    public function decodeWrapsDeserializationFailures(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => 'not-a-serialized-object', 'headers' => ['type' => 'greeting']]);
    }

    #[Test]
    public function traceContextStampRoundTripsAsXNexusTraceContextHeader(): void
    {
        $carrier = ['traceparent' => '00-abc-def-01', 'tracestate' => 'vendor=value'];
        $envelope = new Envelope(new Greeting('hi'), [new TraceContextStamp($carrier)]);

        $decoded = $this->serializer->decode($this->serializer->encode($envelope));
        $stamp = $decoded->last(TraceContextStamp::class);

        self::assertInstanceOf(TraceContextStamp::class, $stamp);
        self::assertSame($carrier, $stamp->carrier);
    }

    #[Test]
    public function malformedTraceContextHeaderIsSkippedWithoutException(): void
    {
        $encoded = $this->serializer->encode(new Envelope(new Greeting('hi')));
        $encoded['headers']['X-Nexus-Trace-Context'] = 'not-valid-json{{{';

        $decoded = $this->serializer->decode($encoded);

        self::assertNull($decoded->last(TraceContextStamp::class));
    }

    #[Test]
    public function jsonArrayTraceContextHeaderIsSkippedWithoutException(): void
    {
        $encoded = $this->serializer->encode(new Envelope(new Greeting('hi')));
        $encoded['headers']['X-Nexus-Trace-Context'] = '[1,2,3]';

        $decoded = $this->serializer->decode($encoded);

        self::assertNull($decoded->last(TraceContextStamp::class));
    }

    #[Test]
    public function jsonObjectWithNonStringValueTraceContextHeaderIsSkippedWithoutException(): void
    {
        $encoded = $this->serializer->encode(new Envelope(new Greeting('hi')));
        $encoded['headers']['X-Nexus-Trace-Context'] = '{"x":42}';

        $decoded = $this->serializer->decode($encoded);

        self::assertNull($decoded->last(TraceContextStamp::class));
    }

    protected function setUp(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Greeting::class);
        $this->serializer = new NexusMessengerSerializer(
            new PhpNativeSerializer([Greeting::class]),
            $registry,
        );
    }
}
