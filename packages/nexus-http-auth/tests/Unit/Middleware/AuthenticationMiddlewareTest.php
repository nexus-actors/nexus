<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Auth\Authenticator;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(AuthenticationMiddleware::class)]
final class AuthenticationMiddlewareTest extends TestCase
{
    #[Test]
    public function stamps_principal_when_authenticator_returns_one(): void
    {
        $alice = new SimplePrincipal('alice');
        $captured = new CapturingHandler();

        $mw = new AuthenticationMiddleware(new StubAuthenticatorMw($alice));
        $mw->process((new Psr17Factory())->createServerRequest('GET', '/'), $captured);

        self::assertNotNull($captured->capturedRequest);
        self::assertSame($alice, $captured->capturedRequest->getAttribute('principal'));
    }

    #[Test]
    public function leaves_request_unchanged_when_authenticator_returns_null(): void
    {
        $captured = new CapturingHandler();

        $mw = new AuthenticationMiddleware(new StubAuthenticatorMw(null));
        $mw->process((new Psr17Factory())->createServerRequest('GET', '/'), $captured);

        self::assertNotNull($captured->capturedRequest);
        self::assertNull($captured->capturedRequest->getAttribute('principal'));
    }
}

final readonly class StubAuthenticatorMw implements Authenticator
{
    public function __construct(private ?Principal $principal) {}

    #[Override]
    public function authenticate(ServerRequestInterface $request): ?Principal
    {
        return $this->principal;
    }
}

final class CapturingHandler implements RequestHandlerInterface
{
    public ?ServerRequestInterface $capturedRequest = null;

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->capturedRequest = $request;

        return new Response(200);
    }
}
