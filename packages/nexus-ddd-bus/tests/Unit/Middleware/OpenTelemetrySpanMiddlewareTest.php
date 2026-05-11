<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Middleware\OpenTelemetrySpanMiddleware;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(OpenTelemetrySpanMiddleware::class)]
final class OpenTelemetrySpanMiddlewareTest extends TestCase
{
    #[Test]
    public function noopDefaultJustDelegatesToNext(): void
    {
        $envelope = $this->envelope();
        $captured = null;

        $next = static function (Envelope $env) use (&$captured): string {
            $captured = $env;

            return 'next-result';
        };

        $result = new OpenTelemetrySpanMiddleware()->process($envelope, Closure::fromCallable($next));

        self::assertSame('next-result', $result);
        self::assertSame($envelope, $captured);
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
            ),
        );
    }
}
