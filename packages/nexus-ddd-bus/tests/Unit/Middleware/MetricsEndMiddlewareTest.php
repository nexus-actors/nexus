<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\AccessDeniedException;
use Monadial\Nexus\Ddd\Bus\Exception\RetryBudgetExhaustedException;
use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricOutcome;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsTimingStamp;
use Monadial\Nexus\Ddd\Bus\Middleware\MetricsEndMiddleware;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMetricsCollector;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

use function microtime;

#[CoversClass(MetricsEndMiddleware::class)]
final class MetricsEndMiddlewareTest extends TestCase
{
    #[Test]
    public function successEmitsSucceededOutcome(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);
        $envelope = $this->envelope();

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame('next', $result);
        self::assertCount(1, $metrics->records);
        self::assertSame('count', $metrics->records[0]['kind']);
        self::assertSame('ddd.command.count', $metrics->records[0]['name']);
        self::assertSame(MetricOutcome::Succeeded->value, $metrics->records[0]['tags']['outcome']);
        self::assertSame(stdClass::class, $metrics->records[0]['tags']['type']);
    }

    #[Test]
    public function validationFailedExceptionEmitsValidationFailedOutcomeAndRethrows(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);
        $failure = ValidationFailedException::with(Violations::empty());

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $metrics->records);
        self::assertSame(MetricOutcome::ValidationFailed->value, $metrics->records[0]['tags']['outcome']);
    }

    #[Test]
    public function accessDeniedExceptionEmitsAccessDeniedOutcomeAndRethrows(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);
        $failure = AccessDeniedException::for('test:policy', 'subject', null);

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $metrics->records);
        self::assertSame(MetricOutcome::AccessDenied->value, $metrics->records[0]['tags']['outcome']);
    }

    #[Test]
    public function retryBudgetExhaustedEmitsOccRetryExhaustedAndRethrows(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);
        $failure = RetryBudgetExhaustedException::for(3, 100, new RuntimeException('occ'));

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $metrics->records);
        self::assertSame(MetricOutcome::OccRetryExhausted->value, $metrics->records[0]['tags']['outcome']);
    }

    #[Test]
    public function otherTerminalFailureEmitsTerminalFailureAndRethrows(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);
        $failure = new MetricsEndTestTerminalFailure('terminal');

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $metrics->records);
        self::assertSame(MetricOutcome::TerminalFailure->value, $metrics->records[0]['tags']['outcome']);
    }

    #[Test]
    public function unclassifiedThrowableDoesNotEmitAndRethrows(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);
        $failure = new RuntimeException('infra');

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame([], $metrics->records);
    }

    #[Test]
    public function emitsDurationHistogramOnSuccessWhenStampPresent(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);
        $envelope = $this->envelope()->with(new MetricsTimingStamp(microtime(true) - 0.05));

        $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        $histogramRecords = [];

        foreach ($metrics->records as $record) {
            if ($record['kind'] === 'histogram') {
                $histogramRecords[] = $record;
            }
        }

        self::assertCount(1, $histogramRecords);
        self::assertSame('ddd.command.duration_ms', $histogramRecords[0]['name']);
        self::assertSame(['type' => stdClass::class], $histogramRecords[0]['tags']);
        self::assertGreaterThan(0.0, $histogramRecords[0]['value']);
    }

    #[Test]
    public function emitsDurationHistogramOnTerminalFailureWhenStampPresent(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);
        $envelope = $this->envelope()->with(new MetricsTimingStamp(microtime(true) - 0.05));
        $failure = ValidationFailedException::with(Violations::empty());

        try {
            $middleware->process(
                $envelope,
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        $histogramRecords = [];

        foreach ($metrics->records as $record) {
            if ($record['kind'] === 'histogram') {
                $histogramRecords[] = $record;
            }
        }

        self::assertCount(1, $histogramRecords);
        self::assertSame('ddd.command.duration_ms', $histogramRecords[0]['name']);
    }

    #[Test]
    public function omitsDurationHistogramWhenStampAbsent(): void
    {
        $metrics = new RecordingMetricsCollector();
        $middleware = new MetricsEndMiddleware($metrics);

        $middleware->process(
            $this->envelope(),
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        foreach ($metrics->records as $record) {
            self::assertNotSame('histogram', $record['kind']);
        }
    }

    /** @return Envelope<stdClass> */
    private function envelope(): Envelope
    {
        return new Envelope(
            new stdClass(),
            new MessageMetadata(
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
                headers: Headers::empty(),
            ),
        );
    }
}

final class MetricsEndTestTerminalFailure extends RuntimeException implements TerminalFailure {}
