<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Middleware\HandlerInvocationMiddleware;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Header\Headers;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandlerInvocationMiddleware::class)]
final class HandlerInvocationMiddlewareTest extends TestCase
{
    #[Test]
    public function handlerIsInvokedAndPipelineContinues(): void
    {
        $handler = new HandlerInvocationTestHandler();
        $locator = new HandlerInvocationTestLocator($handler);
        $middleware = new HandlerInvocationMiddleware($locator);
        $command = new HandlerInvocationTestCommand('hello');
        $envelope = $this->envelope($command);
        $nextRan = false;

        $result = $middleware->process(
            $envelope,
            Closure::fromCallable(static function (Envelope $e) use (&$nextRan): string {
                $nextRan = true;

                return 'next';
            }),
        );

        self::assertSame('next', $result);
        self::assertTrue($nextRan);
        self::assertCount(1, $handler->received);
        self::assertSame($command, $handler->received[0]);
    }

    #[Test]
    public function handlerNotFoundPropagatesException(): void
    {
        $locator = new HandlerInvocationMissingLocator();
        $middleware = new HandlerInvocationMiddleware($locator);
        $command = new HandlerInvocationTestCommand('hello');

        $this->expectException(HandlerNotFoundException::class);

        $middleware->process(
            $this->envelope($command),
            Closure::fromCallable(static fn(Envelope $e): string => 'next'),
        );
    }

    #[Test]
    public function nextIsNotCalledWhenHandlerNotFound(): void
    {
        $locator = new HandlerInvocationMissingLocator();
        $middleware = new HandlerInvocationMiddleware($locator);
        $nextCalled = false;

        try {
            $middleware->process(
                $this->envelope(new HandlerInvocationTestCommand('hello')),
                Closure::fromCallable(static function (Envelope $e) use (&$nextCalled): string {
                    $nextCalled = true;

                    return 'next';
                }),
            );
            self::fail('expected HandlerNotFoundException');
        } catch (HandlerNotFoundException) {
            self::assertFalse($nextCalled);
        }
    }

    /** @return Envelope<Command> */
    private function envelope(Command $message): Envelope
    {
        return new Envelope(
            $message,
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

final readonly class HandlerInvocationTestCommand implements Command
{
    public function __construct(public string $value) {}
}

final class HandlerInvocationTestHandler implements CommandHandler
{
    /** @var list<Command> */
    public array $received = [];

    public function __invoke(HandlerInvocationTestCommand $command): void
    {
        $this->received[] = $command;
    }
}

final class HandlerInvocationTestLocator implements CommandHandlerLocator
{
    public function __construct(private readonly CommandHandler $handler) {}

    #[Override]
    public function locate(Command $command): CommandHandler
    {
        return $this->handler;
    }
}

final class HandlerInvocationMissingLocator implements CommandHandlerLocator
{
    #[Override]
    public function locate(Command $command): CommandHandler
    {
        throw new HandlerNotFoundException('no handler for ' . $command::class);
    }
}
