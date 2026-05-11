<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Bus\Exception\ActorWriterInvariantViolation;
use Monadial\Nexus\Ddd\Bus\Exception\RetryBudgetExhaustedException;
use Monadial\Nexus\Ddd\Bus\Middleware\OccRetryMiddleware;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingLogger;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMetricsCollector;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy;
use Monadial\Nexus\Ddd\Messaging\Retry\NoRetry;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(OccRetryMiddleware::class)]
final class OccRetryMiddlewareTest extends TestCase
{
    #[Test]
    public function syncProfileSuccessOnFirstTryDoesNotRetry(): void
    {
        $clock = new OccRetryManualClock();
        $metrics = new RecordingMetricsCollector();
        $logger = new RecordingLogger();
        $middleware = new OccRetryMiddleware(
            Profile::Sync,
            new NoRetry(),
            $clock,
            $logger,
            $metrics,
            defaultBudgetMs: 1_000,
        );
        $nextCalls = 0;

        $result = $middleware->process(
            $this->envelope(),
            Closure::fromCallable(static function (Envelope $e) use (&$nextCalls): string {
                $nextCalls++;

                return 'next';
            }),
        );

        self::assertSame('next', $result);
        self::assertSame(1, $nextCalls);
        self::assertSame([], $metrics->records);
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function syncProfileRetriesOnOccCollisionThenSucceeds(): void
    {
        $clock = new OccRetryManualClock();
        $metrics = new RecordingMetricsCollector();
        $logger = new RecordingLogger();
        $middleware = new OccRetryMiddleware(
            Profile::Sync,
            new OccRetryNoSleepBackoff(),
            $clock,
            $logger,
            $metrics,
            defaultBudgetMs: 1_000,
        );
        $attempt = 0;
        $envelope = $this->envelope();

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static function (Envelope $e) use (&$attempt): string {
                $attempt++;

                if ($attempt === 1) {
                    throw new OptimisticLockException(stdClass::class, 'order-1', 1, 2);
                }

                return 'recovered';
            }),
        );

        self::assertSame('recovered', $result);
        self::assertSame(2, $attempt);
        self::assertSame([], $metrics->records);
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function syncProfileExhaustsBudgetEmitsMetricLogsWarningAndThrows(): void
    {
        $clock = new OccRetryManualClock();
        $metrics = new RecordingMetricsCollector();
        $logger = new RecordingLogger();
        $middleware = new OccRetryMiddleware(
            Profile::Sync,
            new OccRetryNoSleepBackoff(),
            $clock,
            $logger,
            $metrics,
            defaultBudgetMs: 50,
        );
        $cause = new OptimisticLockException(stdClass::class, 'order-1', 1, 2);
        // Advance clock past budget on every read so the first catch trips exhaustion.
        $clock->advanceMsOnRead = 100;

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $cause),
            );
            self::fail('expected RetryBudgetExhaustedException');
        } catch (Throwable $caught) {
            self::assertInstanceOf(RetryBudgetExhaustedException::class, $caught);
            self::assertSame($cause, $caught->getPrevious());
        }

        self::assertCount(1, $metrics->records);
        self::assertSame('count', $metrics->records[0]['kind']);
        self::assertSame('ddd.command.retry_exhausted', $metrics->records[0]['name']);
        self::assertSame(stdClass::class, $metrics->records[0]['tags']['type']);

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('ddd.command.retry_exhausted', $logger->records[0]['message']);

        $context = $logger->records[0]['context'];
        self::assertSame(50, $context['budget_ms']);
        self::assertSame(stdClass::class, $context['type']);
        self::assertArrayHasKey('attempts', $context);
        self::assertArrayHasKey('cause', $context);
        self::assertArrayHasKey('messageId', $context);
    }

    #[Test]
    public function syncProfileNonOccExceptionPropagatesWithoutRetry(): void
    {
        $clock = new OccRetryManualClock();
        $metrics = new RecordingMetricsCollector();
        $logger = new RecordingLogger();
        $middleware = new OccRetryMiddleware(
            Profile::Sync,
            new OccRetryNoSleepBackoff(),
            $clock,
            $logger,
            $metrics,
            defaultBudgetMs: 1_000,
        );
        $failure = new RuntimeException('boom');
        $attempt = 0;

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static function (Envelope $e) use (&$attempt, $failure): void {
                    $attempt++;

                    throw $failure;
                }),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(1, $attempt);
        self::assertSame([], $metrics->records);
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function actorProfileWrapsOptimisticLockExceptionAsInvariantViolation(): void
    {
        $clock = new OccRetryManualClock();
        $metrics = new RecordingMetricsCollector();
        $logger = new RecordingLogger();
        $middleware = new OccRetryMiddleware(
            Profile::Actor,
            new NoRetry(),
            $clock,
            $logger,
            $metrics,
            defaultBudgetMs: 1_000,
        );
        $cause = new OptimisticLockException(stdClass::class, 'order-1', 1, 2);
        $attempt = 0;

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static function (Envelope $e) use (&$attempt, $cause): void {
                    $attempt++;

                    throw $cause;
                }),
            );
            self::fail('expected ActorWriterInvariantViolation');
        } catch (Throwable $caught) {
            self::assertInstanceOf(ActorWriterInvariantViolation::class, $caught);
        }

        self::assertSame(1, $attempt, 'Actor profile must not retry');
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

final class OccRetryManualClock implements ClockInterface
{
    public int $advanceMsOnRead = 0;

    private DateTimeImmutable $now;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-05-10T00:00:00', new DateTimeZone('UTC'));
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        $value = $this->now;

        if ($this->advanceMsOnRead > 0) {
            $micros = $this->advanceMsOnRead * 1_000;
            $modified = $this->now->modify('+' . $micros . ' microseconds');
            $this->now = $modified !== false
                ? $modified
                : $this->now;
        }

        return $value;
    }
}

final readonly class OccRetryNoSleepBackoff implements BackoffStrategy
{
    /** @return Option<FiniteDuration> */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return Option::some(FiniteDuration::fromTimeUnit(0, TimeUnit::Microseconds()));
    }
}
