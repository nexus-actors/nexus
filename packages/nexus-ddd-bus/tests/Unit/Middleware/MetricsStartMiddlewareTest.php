<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricOutcome;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsTimingStamp;
use Monadial\Nexus\Ddd\Bus\Middleware\MetricsStartMiddleware;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMetricsCollector;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(MetricsStartMiddleware::class)]
final class MetricsStartMiddlewareTest extends TestCase
{
    #[Test]
    public function emitsStartedCounterWithCanonicalTags(): void
    {
        $metrics = new RecordingMetricsCollector();
        $envelope = $this->envelope();

        new MetricsStartMiddleware($metrics)->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertCount(1, $metrics->records);
        self::assertSame('count', $metrics->records[0]['kind']);
        self::assertSame('ddd.command.count', $metrics->records[0]['name']);
        self::assertSame(1, $metrics->records[0]['value']);
        self::assertSame(
            ['outcome' => MetricOutcome::Started->value, 'type' => stdClass::class],
            $metrics->records[0]['tags'],
        );
    }

    #[Test]
    public function callsNextAndReturnsItsResult(): void
    {
        $metrics = new RecordingMetricsCollector();
        $envelope = $this->envelope();
        $captured = null;

        $next = static function (Envelope $env) use (&$captured): string {
            $captured = $env;

            return 'next-result';
        };

        $result = new MetricsStartMiddleware($metrics)->process($envelope, Closure::fromCallable($next));

        self::assertSame('next-result', $result);
        self::assertInstanceOf(Envelope::class, $captured);
        self::assertSame($envelope->message, $captured->message);
    }

    #[Test]
    public function stampsEnvelopeWithMetricsTimingStamp(): void
    {
        $metrics = new RecordingMetricsCollector();
        $envelope = $this->envelope();
        $captured = null;

        $next = static function (Envelope $env) use (&$captured): string {
            $captured = $env;

            return 'next';
        };

        new MetricsStartMiddleware($metrics)->process($envelope, Closure::fromCallable($next));

        self::assertInstanceOf(Envelope::class, $captured);
        $stamp = $captured->get(MetricsTimingStamp::class)->getUnsafe();
        self::assertGreaterThan(0.0, $stamp->startMicros);
    }

    #[Test]
    public function tagsCarryTheConcreteMessageFqn(): void
    {
        $metrics = new RecordingMetricsCollector();
        $message = new readonly class {};
        $envelope = new Envelope($message, $this->metadata());

        new MetricsStartMiddleware($metrics)->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame($message::class, $metrics->records[0]['tags']['type']);
    }

    /** @return Envelope<stdClass> */
    private function envelope(): Envelope
    {
        return new Envelope(new stdClass(), $this->metadata());
    }

    private function metadata(): MessageMetadata
    {
        return new MessageMetadata(
            id: MessageId::generate(),
            occurredAt: new DateTimeImmutable('2026-05-10T00:00:00', new DateTimeZone('UTC')),
            causationId: Option::none(),
            correlationId: Option::none(),
            conversationId: Option::none(),
            schemaVersion: 1,
            traceParent: Option::none(),
            traceState: Option::none(),
            expiresAt: Option::none(),
            vectorClock: Option::none(),
        );
    }
}
