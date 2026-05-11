<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\RetryableFailure;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKey;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKeyResolver;
use Monadial\Nexus\Ddd\Bus\Idempotency\InMemoryIdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Idempotency\ReservationStamp;
use Monadial\Nexus\Ddd\Bus\Middleware\IdempotencyReserveMiddleware;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Monadial\Nexus\Ddd\Bus\Tests\Support\AlwaysNoneStore;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingIdempotencyStore;
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

#[CoversClass(IdempotencyReserveMiddleware::class)]
final class IdempotencyReserveMiddlewareTest extends TestCase
{
    #[Test]
    public function syncProfilePassesThroughWithoutReserving(): void
    {
        $store = new RecordingIdempotencyStore();
        $middleware = new IdempotencyReserveMiddleware(
            $store,
            new IdempotencyKeyResolver(),
            new HandlerAttributeIndex([]),
            Profile::Sync,
        );
        $envelope = $this->envelope();
        $nextCalled = false;

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static function (Envelope $e) use (&$nextCalled): string {
                $nextCalled = true;

                return 'next';
            }),
        );

        self::assertSame('next', $result);
        self::assertTrue($nextCalled);
        self::assertSame([], $store->tryReserveCalls);
    }

    #[Test]
    public function optedOutHandlerPassesThroughWithoutReserving(): void
    {
        $store = new RecordingIdempotencyStore();
        $entry = $this->entry(idempotencyOptedOut: true);
        $middleware = new IdempotencyReserveMiddleware(
            $store,
            new IdempotencyKeyResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            Profile::Async,
        );
        $envelope = $this->envelope();

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame('next', $result);
        self::assertSame([], $store->tryReserveCalls);
    }

    #[Test]
    public function happyPathReservesAndReturnsResultWithoutCommitting(): void
    {
        $store = new InMemoryIdempotencyStore();
        $entry = $this->entry();
        $middleware = new IdempotencyReserveMiddleware(
            $store,
            new IdempotencyKeyResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            Profile::Async,
        );
        $envelope = $this->envelope();

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame('next', $result);

        $secondAttempt = $store->tryReserve(
            'App\\Handler\\TestHandler',
            new IdempotencyKey($envelope->metadata->id->value()),
        );
        self::assertTrue(
            $secondAttempt->isNone(),
            'reservation must remain held — markCompleted is IdempotencyCommitMiddleware\'s job, not Reserve\'s',
        );
    }

    #[Test]
    public function shortCircuitReturnsNullWhenStoreReportsAlreadyHandled(): void
    {
        $store = new AlwaysNoneStore();
        $entry = $this->entry();
        $middleware = new IdempotencyReserveMiddleware(
            $store,
            new IdempotencyKeyResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            Profile::Async,
        );
        $nextCalled = false;

        $result = $middleware->process(
            $this->envelope(),
            Closure::fromCallable(static function (Envelope $e) use (&$nextCalled): null {
                $nextCalled = true;

                return null;
            }),
        );

        self::assertNull($result);
        self::assertFalse($nextCalled);
    }

    #[Test]
    public function retryableFailureReleasesReservationAndRethrows(): void
    {
        $store = new RecordingIdempotencyStore();
        $entry = $this->entry();
        $middleware = new IdempotencyReserveMiddleware(
            $store,
            new IdempotencyKeyResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            Profile::Async,
        );
        $failure = new IdempotencyReserveTestRetryableFailure('boom');

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
            self::assertCount(1, $store->releaseCalls);
            self::assertSame([], $store->markCompletedCalls);
        }
    }

    #[Test]
    public function terminalFailureMarksCompletedAndRethrows(): void
    {
        $store = new RecordingIdempotencyStore();
        $entry = $this->entry();
        $middleware = new IdempotencyReserveMiddleware(
            $store,
            new IdempotencyKeyResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            Profile::Async,
        );
        $failure = new IdempotencyReserveTestTerminalFailure('terminal');

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
            self::assertCount(1, $store->markCompletedCalls);
            self::assertSame([], $store->releaseCalls);
        }
    }

    #[Test]
    public function infrastructureFailureReleasesReservationAndRethrows(): void
    {
        $store = new RecordingIdempotencyStore();
        $entry = $this->entry();
        $middleware = new IdempotencyReserveMiddleware(
            $store,
            new IdempotencyKeyResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            Profile::Async,
        );
        $failure = new RuntimeException('infra');

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
            self::assertCount(1, $store->releaseCalls);
            self::assertSame([], $store->markCompletedCalls);
        }
    }

    #[Test]
    public function envelopePassedToNextCarriesReservationStamp(): void
    {
        $store = new InMemoryIdempotencyStore();
        $entry = $this->entry();
        $middleware = new IdempotencyReserveMiddleware(
            $store,
            new IdempotencyKeyResolver(),
            new HandlerAttributeIndex([stdClass::class => $entry]),
            Profile::Async,
        );
        $captured = null;

        $middleware->process(
            $this->envelope(),
            Closure::fromCallable(static function (Envelope $e) use (&$captured): string {
                $captured = $e;

                return 'next';
            }),
        );

        self::assertNotNull($captured);
        $stamp = $captured->get(ReservationStamp::class);
        self::assertTrue($stamp->isSome());
        self::assertSame('App\\Handler\\TestHandler', $stamp->getUnsafe()->reservation->handlerClass());
    }

    private function entry(bool $idempotencyOptedOut = false): ResolvedAttributesEntry
    {
        return new ResolvedAttributesEntry(
            handlerClass: 'App\\Handler\\TestHandler',
            attributes: [],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: $idempotencyOptedOut,
        );
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

final class IdempotencyReserveTestRetryableFailure extends RuntimeException implements RetryableFailure {}

final class IdempotencyReserveTestTerminalFailure extends RuntimeException implements TerminalFailure {}
