<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryReservation;
use Monadial\Nexus\Ddd\Bus\Idempotency\ReservationStamp;
use Monadial\Nexus\Ddd\Bus\Middleware\IdempotencyCommitMiddleware;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingIdempotencyStore;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

#[CoversClass(IdempotencyCommitMiddleware::class)]
final class IdempotencyCommitMiddlewareTest extends TestCase
{
    #[Test]
    public function syncProfileSkipsCommitEvenWhenStampPresent(): void
    {
        $store = new RecordingIdempotencyStore();
        $middleware = new IdempotencyCommitMiddleware($store, Profile::Sync);
        $envelope = $this->envelopeWithStamp();

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame('next', $result);
        self::assertSame([], $store->markCompletedCalls);
    }

    #[Test]
    public function asyncProfileWithoutStampDoesNotCommit(): void
    {
        $store = new RecordingIdempotencyStore();
        $middleware = new IdempotencyCommitMiddleware($store, Profile::Async);
        $envelope = $this->envelope();

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame('next', $result);
        self::assertSame([], $store->markCompletedCalls);
    }

    #[Test]
    public function asyncProfileWithStampCommitsAfterNext(): void
    {
        $store = new RecordingIdempotencyStore();
        $middleware = new IdempotencyCommitMiddleware($store, Profile::Async);
        $reservation = new InMemoryReservation(stdClass::class, new IdempotencyKey('k'), 'composite::k');
        $envelope = $this->envelope()->with(new ReservationStamp($reservation));

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame('next', $result);
        self::assertCount(1, $store->markCompletedCalls);
        self::assertSame($reservation, $store->markCompletedCalls[0]);
    }

    #[Test]
    public function markCompletedRunsAfterHandlerReturns(): void
    {
        $store = new RecordingIdempotencyStore();
        $middleware = new IdempotencyCommitMiddleware($store, Profile::Async);
        $reservation = new InMemoryReservation(stdClass::class, new IdempotencyKey('k'), 'composite::k');
        $envelope = $this->envelope()->with(new ReservationStamp($reservation));
        $events = [];

        $middleware->process(
            $envelope,
            Closure::fromCallable(static function (Envelope $e) use (&$events): string {
                $events[] = 'handler-ran';

                return 'next';
            }),
        );

        $events[] = 'after-process';

        self::assertSame(['handler-ran', 'after-process'], $events);
        self::assertCount(1, $store->markCompletedCalls);
    }

    #[Test]
    public function handlerExceptionPropagatesAndCommitIsNotCalled(): void
    {
        $store = new RecordingIdempotencyStore();
        $middleware = new IdempotencyCommitMiddleware($store, Profile::Async);
        $reservation = new InMemoryReservation(stdClass::class, new IdempotencyKey('k'), 'composite::k');
        $envelope = $this->envelope()->with(new ReservationStamp($reservation));
        $failure = new RuntimeException('handler-failed');

        try {
            $middleware->process(
                $envelope,
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected handler exception to propagate');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
            self::assertSame([], $store->markCompletedCalls);
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

    /** @return Envelope<stdClass> */
    private function envelopeWithStamp(): Envelope
    {
        $reservation = new InMemoryReservation(stdClass::class, new IdempotencyKey('sync-k'), 'composite::sync-k');

        return $this->envelope()->with(new ReservationStamp($reservation));
    }
}
