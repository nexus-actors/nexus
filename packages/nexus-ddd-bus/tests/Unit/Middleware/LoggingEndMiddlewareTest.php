<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Middleware\LoggingEndMiddleware;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingLogger;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Throwable;

use function str_repeat;
use function strlen;
use function substr;

#[CoversClass(LoggingEndMiddleware::class)]
final class LoggingEndMiddlewareTest extends TestCase
{
    #[Test]
    public function successEmitsInfoCompletedAndPropagatesResult(): void
    {
        $logger = new RecordingLogger();
        $middleware = new LoggingEndMiddleware($logger);
        $envelope = $this->envelope();

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        self::assertSame('next', $result);
        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level']);
        self::assertSame('ddd.command.completed', $logger->records[0]['message']);
        self::assertSame($envelope->metadata->id->value(), $logger->records[0]['context']['messageId']);
        self::assertSame(stdClass::class, $logger->records[0]['context']['messageType']);
    }

    #[Test]
    public function throwableEmitsWarningFailedAndRethrows(): void
    {
        $logger = new RecordingLogger();
        $middleware = new LoggingEndMiddleware($logger);
        $envelope = $this->envelope();
        $failure = new RuntimeException('boom');

        try {
            $middleware->process(
                $envelope,
                Closure::fromCallable(static fn(Envelope $e) => throw $failure),
            );
            self::fail('expected rethrow');
        } catch (Throwable $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertCount(1, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame('ddd.command.failed', $logger->records[0]['message']);

        $context = $logger->records[0]['context'];
        self::assertSame(RuntimeException::class, $context['exception_class']);
        self::assertSame('boom', $context['exception_message']);
        self::assertSame($envelope->metadata->id->value(), $context['messageId']);
        self::assertSame(stdClass::class, $context['messageType']);
    }

    #[Test]
    public function exceptionMessageLongerThanCapIsTruncatedWithEllipsis(): void
    {
        $logger = new RecordingLogger();
        $middleware = new LoggingEndMiddleware($logger);
        $oversized = str_repeat('a', LoggingEndMiddleware::EXCEPTION_MESSAGE_MAX_LENGTH + 100);

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw new RuntimeException($oversized)),
            );
            self::fail('expected rethrow');
        } catch (Throwable) {
            // expected
        }

        $logged = $logger->records[0]['context']['exception_message'];
        self::assertIsString($logged);
        self::assertSame(LoggingEndMiddleware::EXCEPTION_MESSAGE_MAX_LENGTH + 3, strlen($logged));
        self::assertSame(
            str_repeat('a', LoggingEndMiddleware::EXCEPTION_MESSAGE_MAX_LENGTH),
            substr($logged, 0, LoggingEndMiddleware::EXCEPTION_MESSAGE_MAX_LENGTH),
        );
        self::assertSame('...', substr($logged, -3));
    }

    #[Test]
    public function exceptionMessageAtCapIsNotTruncated(): void
    {
        $logger = new RecordingLogger();
        $middleware = new LoggingEndMiddleware($logger);
        $atCap = str_repeat('b', LoggingEndMiddleware::EXCEPTION_MESSAGE_MAX_LENGTH);

        try {
            $middleware->process(
                $this->envelope(),
                Closure::fromCallable(static fn(Envelope $e) => throw new RuntimeException($atCap)),
            );
            self::fail('expected rethrow');
        } catch (Throwable) {
            // expected
        }

        self::assertSame($atCap, $logger->records[0]['context']['exception_message']);
    }

    #[Test]
    public function successDoesNotEmitFailureRecord(): void
    {
        $logger = new RecordingLogger();
        $middleware = new LoggingEndMiddleware($logger);

        $middleware->process(
            $this->envelope(),
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );

        foreach ($logger->records as $record) {
            self::assertNotSame(LogLevel::WARNING, $record['level']);
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
