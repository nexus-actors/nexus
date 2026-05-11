<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Exception\CausationDepthExceededException;
use Monadial\Nexus\Ddd\Bus\Header\HeaderKeys;
use Monadial\Nexus\Ddd\Bus\Middleware\CausationPropagationMiddleware;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CausationPropagationMiddleware::class)]
final class CausationPropagationMiddlewareTest extends TestCase
{
    #[Test]
    public function firstDispatchSetsDepthToOne(): void
    {
        $envelope = $this->envelope(Headers::empty());
        $captured = null;

        $next = static function (Envelope $env) use (&$captured): string {
            $captured = $env;

            return 'ok';
        };

        $result = new CausationPropagationMiddleware()->process($envelope, Closure::fromCallable($next));

        self::assertSame('ok', $result);
        self::assertInstanceOf(Envelope::class, $captured);
        self::assertSame(
            1,
            $captured->metadata->headers->get(HeaderKeys::CAUSATION_DEPTH)->getOrElse(-1),
        );
    }

    #[Test]
    public function subsequentDispatchIncrementsExistingDepth(): void
    {
        $envelope = $this->envelope(Headers::of([HeaderKeys::CAUSATION_DEPTH => 5]));
        $captured = null;

        $next = static function (Envelope $env) use (&$captured): string {
            $captured = $env;

            return 'ok';
        };

        new CausationPropagationMiddleware()->process($envelope, Closure::fromCallable($next));

        self::assertInstanceOf(Envelope::class, $captured);
        self::assertSame(
            6,
            $captured->metadata->headers->get(HeaderKeys::CAUSATION_DEPTH)->getOrElse(-1),
        );
    }

    #[Test]
    public function depthAtDefaultCapThrowsOnNextDispatch(): void
    {
        $envelope = $this->envelope(Headers::of([HeaderKeys::CAUSATION_DEPTH => 32]));
        $nextCalled = false;
        $next = static function (Envelope $env) use (&$nextCalled): string {
            $nextCalled = true;

            return 'ok';
        };

        $this->expectException(CausationDepthExceededException::class);
        $this->expectExceptionMessage('33');

        try {
            new CausationPropagationMiddleware()->process($envelope, Closure::fromCallable($next));
        } finally {
            self::assertFalse($nextCalled, '$next must not run when the depth cap is breached');
        }
    }

    #[Test]
    public function customDepthCapThrowsWhenExceeded(): void
    {
        $envelope = $this->envelope(Headers::of([HeaderKeys::CAUSATION_DEPTH => 3]));
        $next = static fn(Envelope $env): string => 'ok';

        $this->expectException(CausationDepthExceededException::class);
        $this->expectExceptionMessage('4');

        new CausationPropagationMiddleware(depthCap: 3)->process($envelope, Closure::fromCallable($next));
    }

    #[Test]
    public function preservesMessageAndStampsWhenPropagating(): void
    {
        $envelope = $this->envelope(Headers::empty());
        $captured = null;

        $next = static function (Envelope $env) use (&$captured): string {
            $captured = $env;

            return 'ok';
        };

        new CausationPropagationMiddleware()->process($envelope, Closure::fromCallable($next));

        self::assertInstanceOf(Envelope::class, $captured);
        self::assertSame($envelope->message, $captured->message);
        self::assertSame($envelope->stamps, $captured->stamps);
        self::assertSame($envelope->metadata->id, $captured->metadata->id);
    }

    /** @return Envelope<stdClass> */
    private function envelope(Headers $headers): Envelope
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
                headers: $headers,
            ),
        );
    }
}
