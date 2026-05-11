<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Middleware\EventDrainMiddleware;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingOutbox;
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

#[CoversClass(EventDrainMiddleware::class)]
final class EventDrainMiddlewareTest extends TestCase
{
    #[Test]
    public function flushRunsAfterNextAndResultIsReturned(): void
    {
        $outbox = new RecordingOutbox();
        $middleware = new EventDrainMiddleware($outbox);
        $events = [];

        $result = $middleware->process(
            $this->envelope(),
            Closure::fromCallable(static function (Envelope $e) use (&$events): string {
                $events[] = 'next-ran';

                return 'next';
            }),
        );

        $events[] = 'after-process';

        self::assertSame('next', $result);
        self::assertSame(['next-ran', 'after-process'], $events);
        self::assertSame(1, $outbox->flushCalls);
    }

    #[Test]
    public function nextOrderedBeforeFlushObservedFromCallback(): void
    {
        $outbox = new RecordingOutbox();
        $middleware = new EventDrainMiddleware($outbox);

        $middleware->process(
            $this->envelope(),
            Closure::fromCallable(static function (Envelope $e) use ($outbox): string {
                self::assertSame(0, $outbox->flushCalls, 'flush must not have run yet inside $next');

                return 'next';
            }),
        );

        self::assertSame(1, $outbox->flushCalls);
    }

    #[Test]
    public function nextThrowsAndFlushIsNotCalled(): void
    {
        $outbox = new RecordingOutbox();
        $middleware = new EventDrainMiddleware($outbox);
        $failure = new RuntimeException('handler-failed');

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(0, $outbox->flushCalls);
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
