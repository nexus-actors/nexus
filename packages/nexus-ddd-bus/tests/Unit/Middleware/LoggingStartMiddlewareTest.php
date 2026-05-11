<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\Sensitive;
use Monadial\Nexus\Ddd\Bus\Logging\PayloadRedactor;
use Monadial\Nexus\Ddd\Bus\Middleware\LoggingStartMiddleware;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingLogger;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

#[CoversClass(LoggingStartMiddleware::class)]
final class LoggingStartMiddlewareTest extends TestCase
{
    #[Test]
    public function defaultsToInfoOnlyAndPropagatesToNext(): void
    {
        $logger = new RecordingLogger();
        $envelope = $this->envelope();
        $captured = null;

        $next = static function (Envelope $env) use (&$captured): string {
            $captured = $env;

            return 'next';
        };

        $result = new LoggingStartMiddleware($logger, new PayloadRedactor())
            ->process($envelope, Closure::fromCallable($next));

        self::assertSame('next', $result);
        self::assertSame($envelope, $captured);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level']);
        self::assertSame('ddd.command.dispatched', $logger->records[0]['message']);
    }

    #[Test]
    public function infoContextCarriesEnvelopeIdentifiers(): void
    {
        $logger = new RecordingLogger();
        $envelope = $this->envelope();

        new LoggingStartMiddleware($logger, new PayloadRedactor())
            ->process($envelope, Closure::fromCallable(static fn(Envelope $e): string => 'next'));

        $context = $logger->records[0]['context'];
        self::assertArrayHasKey('causationId', $context);
        self::assertArrayHasKey('correlationId', $context);
        self::assertArrayHasKey('messageId', $context);
        self::assertArrayHasKey('messageType', $context);
        self::assertSame('', $context['causationId']);
        self::assertSame('', $context['correlationId']);
        self::assertSame($envelope->metadata->id->value(), $context['messageId']);
        self::assertSame($envelope->message::class, $context['messageType']);
    }

    #[Test]
    public function causationAndCorrelationIdsAreStringifiedWhenPresent(): void
    {
        $logger = new RecordingLogger();
        $causation = MessageId::generate();
        $correlation = MessageId::generate();
        $envelope = $this->envelopeWith(Option::some($causation), Option::some($correlation));

        new LoggingStartMiddleware($logger, new PayloadRedactor())
            ->process($envelope, Closure::fromCallable(static fn(Envelope $e): string => 'next'));

        self::assertSame($causation->value(), $logger->records[0]['context']['causationId']);
        self::assertSame($correlation->value(), $logger->records[0]['context']['correlationId']);
    }

    #[Test]
    public function payloadAtDebugIsSuppressedByDefault(): void
    {
        $logger = new RecordingLogger();
        $envelope = $this->envelope();

        new LoggingStartMiddleware($logger, new PayloadRedactor())
            ->process($envelope, Closure::fromCallable(static fn(Envelope $e): string => 'next'));

        foreach ($logger->records as $record) {
            self::assertNotSame(LogLevel::DEBUG, $record['level']);
        }
    }

    #[Test]
    public function payloadAtDebugEmitsRedactedPayloadBeforeInfo(): void
    {
        $logger = new RecordingLogger();
        $envelope = new Envelope(
            new readonly class ('tok-x', 'order-1') {
                public function __construct(#[Sensitive] public string $cardToken, public string $orderId) {}
            },
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

        new LoggingStartMiddleware($logger, new PayloadRedactor(), logPayloadAtDebug: true)
            ->process($envelope, Closure::fromCallable(static fn(Envelope $e): string => 'next'));

        self::assertCount(2, $logger->records);
        self::assertSame(LogLevel::DEBUG, $logger->records[0]['level']);
        self::assertSame('ddd.command.dispatched.payload', $logger->records[0]['message']);
        self::assertSame(LogLevel::INFO, $logger->records[1]['level']);

        $debugContext = $logger->records[0]['context'];
        self::assertArrayHasKey('payload', $debugContext);
        self::assertSame(
            ['cardToken' => '[REDACTED]', 'orderId' => 'order-1'],
            $debugContext['payload'],
        );
    }

    /** @return Envelope<object> */
    private function envelope(): Envelope
    {
        return $this->envelopeWith(Option::none(), Option::none());
    }

    /**
     * @param Option<MessageId> $causationId
     * @param Option<MessageId> $correlationId
     * @return Envelope<object>
     */
    private function envelopeWith(Option $causationId, Option $correlationId): Envelope
    {
        return new Envelope(
            new readonly class {},
            new MessageMetadata(
                id: MessageId::generate(),
                occurredAt: new DateTimeImmutable('2026-05-10T00:00:00', new DateTimeZone('UTC')),
                causationId: $causationId,
                correlationId: $correlationId,
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
