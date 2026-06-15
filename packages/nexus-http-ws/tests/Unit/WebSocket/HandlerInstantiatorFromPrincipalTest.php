<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\ArrayContainer;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use stdClass;

#[CoversClass(HandlerInstantiator::class)]
final class HandlerInstantiatorFromPrincipalTest extends TestCase
{
    #[Test]
    public function from_principal_constructor_param_resolves_principal_from_request_attribute(): void
    {
        self::markTestSkipped('Re-enabled in T14 after FromPrincipalResolver registration');

        /** @phpstan-ignore-next-line unreachable */
        $principal = new stdClass();
        $principal->id = 'tomas';

        $request = (new ServerRequest('GET', '/ws/echo'))
            ->withAttribute('principal', $principal);

        $ctx = new PrincipalCarryingWebSocketContext(42, $request);

        $handler = (new HandlerInstantiator(new ArrayContainer()))
            ->instantiate(PrincipalAwareWebSocketHandler::class, $ctx);

        self::assertInstanceOf(PrincipalAwareWebSocketHandler::class, $handler);
        self::assertSame('tomas', $handler->principalId);
    }

    #[Test]
    public function from_principal_throws_when_no_principal_on_request(): void
    {
        self::markTestSkipped('Re-enabled in T14 after FromPrincipalResolver registration');

        /** @phpstan-ignore-next-line unreachable */
        $request = new ServerRequest('GET', '/ws/echo');
        $ctx = new PrincipalCarryingWebSocketContext(7, $request);

        $instantiator = new HandlerInstantiator(new ArrayContainer());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FromPrincipal');

        $instantiator->instantiate(PrincipalAwareWebSocketHandler::class, $ctx);
    }
}

final class PrincipalAwareWebSocketHandler extends WebSocketHandler
{
    public string $principalId;

    public function __construct(#[FromPrincipal] stdClass $principal)
    {
        $this->principalId = (string) $principal->id;
    }

    #[Override]
    public function onMessage(WebSocketFrame $frame): void
    {
        // no-op
    }
}

final class PrincipalCarryingWebSocketContext implements WebSocketContext
{
    public function __construct(private readonly int $id, private readonly ServerRequestInterface $request) {}

    #[Override]
    public function id(): int
    {
        return $this->id;
    }

    #[Override]
    public function request(): ServerRequestInterface
    {
        return $this->request;
    }

    #[Override]
    public function send(string $text): void
    {
        // no-op
    }

    #[Override]
    public function sendBinary(string $data): void
    {
        // no-op
    }

    #[Override]
    public function sendPing(): void
    {
        // no-op
    }

    #[Override]
    public function close(int $code = 1000, string $reason = ''): void
    {
        // no-op
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }
}
