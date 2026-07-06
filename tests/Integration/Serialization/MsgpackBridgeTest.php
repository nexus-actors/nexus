<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Serialization;

use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Messenger\Stamp\CorrelationIdStamp;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Observability\Serialization\Tests\Support\RecordingObservability;
use Monadial\Nexus\Observability\Serialization\TracingMessageSerializer;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monadial\Nexus\Tests\Integration\Serialization\Messages\OrderPlaced;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(MessagePackMessageSerializer::class)]
#[CoversClass(TracingMessageSerializer::class)]
final class MsgpackBridgeTest extends TestCase
{
    #[Test]
    public function fullEnvelopeRoundTripOverInMemoryTransportPreservesMessageAndBridgeStamps(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(OrderPlaced::class);
        $serializer = new NexusMessengerSerializer(new MessagePackMessageSerializer($registry), $registry);
        $transport = new InMemoryTransport($serializer);

        $envelope = new Envelope(new OrderPlaced('ORD-msgpack-1', 149.95), [
            new CorrelationIdStamp('corr-42'),
            new SourceActorPathStamp('nexus://producer-system/user/orders-out'),
            new TargetActorPathStamp('nexus://consumer-system/user/orders'),
        ]);
        $transport->send($envelope);

        $received = [...$transport->get()];
        self::assertCount(1, $received);

        $message = $received[0]->getMessage();
        self::assertInstanceOf(OrderPlaced::class, $message);
        self::assertSame('ORD-msgpack-1', $message->orderId);
        self::assertSame(149.95, $message->amount);

        $correlation = $received[0]->last(CorrelationIdStamp::class);
        self::assertNotNull($correlation);
        self::assertSame('corr-42', $correlation->id);

        $source = $received[0]->last(SourceActorPathStamp::class);
        self::assertNotNull($source);
        self::assertSame('nexus://producer-system/user/orders-out', $source->path);

        $target = $received[0]->last(TargetActorPathStamp::class);
        self::assertNotNull($target);
        self::assertSame('nexus://consumer-system/user/orders', $target->path);
    }

    #[Test]
    public function encodedEnvelopeCarriesPlainStringHeadersAndBinaryBody(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(OrderPlaced::class);
        $serializer = new NexusMessengerSerializer(new MessagePackMessageSerializer($registry), $registry);

        $message = new OrderPlaced('ORD-msgpack-2', 88.25);
        $encoded = $serializer->encode(new Envelope($message, [
            new CorrelationIdStamp('corr-7'),
            new SourceActorPathStamp('nexus://producer-system/user/orders-out'),
        ]));

        self::assertArrayHasKey('body', $encoded);
        self::assertArrayHasKey('headers', $encoded);
        self::assertIsArray($encoded['headers']);

        foreach ($encoded['headers'] as $name => $value) {
            self::assertIsString($name);
            self::assertIsString($value);
        }

        self::assertSame('order.placed', $encoded['headers']['type']);
        self::assertSame('corr-7', $encoded['headers']['X-Nexus-Correlation-Id']);
        self::assertSame('nexus://producer-system/user/orders-out', $encoded['headers']['X-Nexus-Source-Path']);

        $body = $encoded['body'];
        self::assertIsString($body);
        self::assertMatchesRegularExpression('/[\x00-\x1f\x80-\xff]/', $body);
        self::assertNotSame(json_encode($message, JSON_THROW_ON_ERROR), $body);
    }

    #[Test]
    public function tracedMsgpackSerializerRecordsSpansAndMetricsDuringBridgeRoundTrip(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(OrderPlaced::class);
        $observability = new RecordingObservability();
        $traced = new TracingMessageSerializer(new MessagePackMessageSerializer($registry), $observability);
        $transport = new InMemoryTransport(new NexusMessengerSerializer($traced, $registry));

        $transport->send(new Envelope(new OrderPlaced('ORD-msgpack-3', 12.50)));
        $received = [...$transport->get()];

        self::assertCount(1, $received);
        self::assertInstanceOf(OrderPlaced::class, $received[0]->getMessage());

        $spanNames = array_map(
            static fn($span): string => $span->name,
            $observability->tracer->spans,
        );
        self::assertSame(['serialization.serialize', 'serialization.deserialize'], $spanNames);

        foreach ($observability->tracer->spans as $span) {
            self::assertSame(SpanKind::Internal, $span->kind);
            self::assertSame('MessagePackMessageSerializer', $span->attributes['nexus.serializer']);
            self::assertTrue($span->ended);
        }

        // Serialize sees the object (FQCN); decode resolves the wire type name to the FQCN before deserializing.
        self::assertSame(OrderPlaced::class, $observability->tracer->spans[0]->attributes['nexus.message.type']);
        self::assertSame(OrderPlaced::class, $observability->tracer->spans[1]->attributes['nexus.message.type']);

        self::assertSame(2, $observability->meter->counterSum('nexus.serialization.operations'));
        self::assertSame(0, $observability->meter->counterSum('nexus.serialization.failures'));
        self::assertGreaterThan(0, $observability->meter->histogramTotal('nexus.serialization.bytes'));
        self::assertGreaterThan(0.0, $observability->meter->histogramTotal('nexus.serialization.duration'));
    }
}
