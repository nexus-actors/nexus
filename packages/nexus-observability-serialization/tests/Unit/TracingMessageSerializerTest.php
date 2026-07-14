<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Serialization\Tests\Unit;

use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Serialization\Tests\Support\RecordingLogger;
use Monadial\Nexus\Observability\Serialization\Tests\Support\RecordingObservability;
use Monadial\Nexus\Observability\Serialization\TracingMessageSerializer;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Observability\Trace\StatusCode;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\Exception\MessageSerializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
use NoDiscard;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

#[CoversClass(TracingMessageSerializer::class)]
final class TracingMessageSerializerTest extends TestCase
{
    #[Test]
    public function serializeAddsSpanAndRecordsMetrics(): void
    {
        $obs = new RecordingObservability();
        $inner = new TestMessageSerializer('serialized-payload');
        $serializer = new TracingMessageSerializer($inner, $obs);

        $result = $serializer->serialize(new TestMessage());

        self::assertSame('serialized-payload', $result);

        // Span recorded with correct name, kind, and attributes.
        self::assertCount(1, $obs->tracer->spans);
        $span = $obs->tracer->spans[0];
        self::assertSame('serialization.serialize', $span->name);
        self::assertSame(SpanKind::Internal, $span->kind);
        self::assertSame(TestMessage::class, $span->attributes['nexus.message.type']);
        self::assertSame('TestMessageSerializer', $span->attributes['nexus.serializer']);
        self::assertTrue($span->ended);

        // Operations counter incremented once.
        self::assertCount(1, $obs->meter->counters);
        self::assertArrayHasKey('nexus.serialization.operations', $obs->meter->counters);
        $ops = $obs->meter->counters['nexus.serialization.operations'];
        self::assertSame(1, $ops->total);
        self::assertSame('serialize', $ops->adds[0]['attributes']['operation']);
        self::assertSame(TestMessage::class, $ops->adds[0]['attributes']['nexus.message.type']);

        // Bytes histogram records payload size.
        self::assertArrayHasKey('nexus.serialization.bytes', $obs->meter->histograms);
        $bytes = $obs->meter->histograms['nexus.serialization.bytes'];
        self::assertCount(1, $bytes->records);
        self::assertSame(strlen('serialized-payload'), $bytes->records[0]['value']);
        self::assertSame('serialize', $bytes->records[0]['attributes']['operation']);

        // Duration histogram records a non-negative value.
        self::assertArrayHasKey('nexus.serialization.duration', $obs->meter->histograms);
        $dur = $obs->meter->histograms['nexus.serialization.duration'];
        self::assertCount(1, $dur->records);
        self::assertGreaterThanOrEqual(0, $dur->records[0]['value']);
        self::assertSame('serialize', $dur->records[0]['attributes']['operation']);
    }

    #[Test]
    public function deserializeAddsSpanAndRecordsMetrics(): void
    {
        $obs = new RecordingObservability();
        $inner = new TestMessageSerializer('unused', false, new TestMessage());
        $serializer = new TracingMessageSerializer($inner, $obs);
        $payload = '{"type":"TestMessage"}';

        $result = $serializer->deserialize($payload, TestMessage::class);

        self::assertInstanceOf(TestMessage::class, $result);

        // Span recorded with correct name, kind, and attributes.
        self::assertCount(1, $obs->tracer->spans);
        $span = $obs->tracer->spans[0];
        self::assertSame('serialization.deserialize', $span->name);
        self::assertSame(SpanKind::Internal, $span->kind);
        self::assertSame(TestMessage::class, $span->attributes['nexus.message.type']);
        self::assertSame('TestMessageSerializer', $span->attributes['nexus.serializer']);
        self::assertTrue($span->ended);

        // Operations counter incremented once.
        self::assertArrayHasKey('nexus.serialization.operations', $obs->meter->counters);
        $ops = $obs->meter->counters['nexus.serialization.operations'];
        self::assertSame(1, $ops->total);
        self::assertSame('deserialize', $ops->adds[0]['attributes']['operation']);

        // Bytes histogram records input payload size.
        self::assertArrayHasKey('nexus.serialization.bytes', $obs->meter->histograms);
        $bytes = $obs->meter->histograms['nexus.serialization.bytes'];
        self::assertCount(1, $bytes->records);
        self::assertSame(strlen($payload), $bytes->records[0]['value']);
        self::assertSame('deserialize', $bytes->records[0]['attributes']['operation']);

        // Duration histogram records a non-negative value.
        self::assertArrayHasKey('nexus.serialization.duration', $obs->meter->histograms);
        $dur = $obs->meter->histograms['nexus.serialization.duration'];
        self::assertCount(1, $dur->records);
        self::assertGreaterThanOrEqual(0, $dur->records[0]['value']);
    }

    #[Test]
    public function serializeFailurePropagatesRecordsErrorAndLogsWarning(): void
    {
        $obs = new RecordingObservability();
        $logger = new RecordingLogger();
        $inner = new TestMessageSerializer('unused', shouldThrow: true);
        $serializer = new TracingMessageSerializer($inner, $obs, $logger);

        $this->expectException(MessageSerializationException::class);

        try {
            (void) $serializer->serialize(new TestMessage());
        } finally {
            // Span ended with Error status and recorded exception.
            self::assertCount(1, $obs->tracer->spans);
            $span = $obs->tracer->spans[0];
            self::assertSame(StatusCode::Error, $span->status);
            self::assertInstanceOf(MessageSerializationException::class, $span->exception);
            self::assertTrue($span->ended);

            // Failures counter incremented.
            self::assertArrayHasKey('nexus.serialization.failures', $obs->meter->counters);
            self::assertSame(1, $obs->meter->counters['nexus.serialization.failures']->total);

            // Duration histogram still recorded in finally.
            self::assertArrayHasKey('nexus.serialization.duration', $obs->meter->histograms);
            self::assertCount(1, $obs->meter->histograms['nexus.serialization.duration']->records);

            // Logger warning emitted.
            self::assertTrue($logger->hasWarning('Serialization failed'));
        }
    }

    #[Test]
    public function deserializeFailurePropagatesRecordsErrorAndLogsWarning(): void
    {
        $obs = new RecordingObservability();
        $logger = new RecordingLogger();
        $inner = new TestMessageSerializer('unused', shouldThrow: true);
        $serializer = new TracingMessageSerializer($inner, $obs, $logger);

        $this->expectException(MessageDeserializationException::class);

        try {
            (void) $serializer->deserialize('{}', TestMessage::class);
        } finally {
            self::assertCount(1, $obs->tracer->spans);
            $span = $obs->tracer->spans[0];
            self::assertSame(StatusCode::Error, $span->status);
            self::assertInstanceOf(MessageDeserializationException::class, $span->exception);
            self::assertTrue($span->ended);

            self::assertArrayHasKey('nexus.serialization.failures', $obs->meter->counters);
            self::assertSame(1, $obs->meter->counters['nexus.serialization.failures']->total);

            self::assertTrue($logger->hasWarning('Deserialization failed'));
        }
    }

    #[Test]
    public function disabledObservabilityWithNoLoggerDelegatesDirectly(): void
    {
        $obs = new RecordingObservability(enabled: false);
        $inner = new TestMessageSerializer('direct-result');
        $serializer = new TracingMessageSerializer($inner, $obs);

        $result = $serializer->serialize(new TestMessage());

        self::assertSame('direct-result', $result);
        // Pure delegation: no spans created.
        self::assertCount(0, $obs->tracer->spans);
    }

    #[Test]
    public function serializeFailureWithNoLoggerDoesNotCrash(): void
    {
        $obs = new RecordingObservability();
        $inner = new TestMessageSerializer('unused', shouldThrow: true);
        // No logger injected — null-safe call must not crash.
        $serializer = new TracingMessageSerializer($inner, $obs);

        $this->expectException(MessageSerializationException::class);

        (void) $serializer->serialize(new TestMessage());
    }

    #[Test]
    public function noopObservabilityWithLoggerStillLogsOnFailure(): void
    {
        $logger = new RecordingLogger();
        $inner = new TestMessageSerializer('unused', shouldThrow: true);
        $serializer = new TracingMessageSerializer($inner, new NoopObservability(), $logger);

        $this->expectException(MessageSerializationException::class);

        try {
            (void) $serializer->serialize(new TestMessage());
        } finally {
            self::assertTrue($logger->hasWarning('Serialization failed'));
        }
    }
}

final class TestMessage {}

final readonly class TestMessageSerializer implements MessageSerializer
{
    public function __construct(
        private string $serialized = 'serialized-payload',
        private bool $shouldThrow = false,
        private ?object $deserialized = null,
    ) {}

    #[Override]
    #[NoDiscard]
    public function serialize(object $message): string
    {
        if ($this->shouldThrow) {
            throw new MessageSerializationException($message::class, 'test failure');
        }

        return $this->serialized;
    }

    #[Override]
    #[NoDiscard]
    public function deserialize(string $data, string $type): object
    {
        if ($this->shouldThrow) {
            throw new MessageDeserializationException($type, 'test failure');
        }

        return $this->deserialized ?? new TestMessage();
    }
}
