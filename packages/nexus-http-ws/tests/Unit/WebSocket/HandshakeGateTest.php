<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Middleware\MiddlewareResolver;
use Monadial\Nexus\Http\Ws\WebSocket\HandshakeGate;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRoute;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketRouter;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;

/** Stamps a fake principal attribute, mirroring AuthenticationMiddleware. */
final readonly class _StampingMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('principal', 'user-42'));
    }
}

/** Rejects with 401 unless the request carries a token query param. */
final readonly class _RejectingMiddleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $params = $request->getQueryParams();

        if (($params['token'] ?? '') !== 'valid') {
            return new Response(401, [], 'unauthorized');
        }

        return $handler->handle($request);
    }
}

/** Records the attributes it observed for assertion. */
final class _ObservingMiddleware implements MiddlewareInterface
{
    /** @var array<string, mixed> */
    public array $seen = [];

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->seen = $request->getAttributes();

        return $handler->handle($request);
    }
}

/** Returns 101 itself without calling the next handler — must NOT authorize. */
final readonly class _Fake101Middleware implements MiddlewareInterface
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return new Response(101);
    }
}

#[CoversClass(HandshakeGate::class)]
final class HandshakeGateTest extends TestCase
{
    #[Test]
    public function unmatched_path_is_rejected_with_404_before_upgrade(): void
    {
        $gate = $this->gate([WebSocketRoute::handler('/ws/echo', stdClass::class)]);

        $result = $gate->evaluate(new ServerRequest('GET', '/nope'));

        self::assertFalse($result->isAuthorized());
        self::assertNotNull($result->rejection);
        self::assertSame(404, $result->rejection->getStatusCode());
    }

    #[Test]
    public function matched_route_without_middleware_is_authorized(): void
    {
        $gate = $this->gate([WebSocketRoute::handler('/ws/echo', stdClass::class)]);

        $result = $gate->evaluate(new ServerRequest('GET', '/ws/echo'));

        self::assertTrue($result->isAuthorized());
        self::assertNotNull($result->request);
    }

    #[Test]
    public function rejecting_middleware_blocks_the_upgrade_with_its_response(): void
    {
        $gate = $this->gate(
            [WebSocketRoute::handler('/ws/secure', stdClass::class)],
            [new _RejectingMiddleware()],
        );

        $result = $gate->evaluate(new ServerRequest('GET', '/ws/secure'));

        self::assertFalse($result->isAuthorized());
        self::assertNotNull($result->rejection);
        self::assertSame(401, $result->rejection->getStatusCode());
    }

    #[Test]
    public function passing_middleware_authorizes_and_mutations_are_captured(): void
    {
        $gate = $this->gate(
            [WebSocketRoute::handler('/ws/secure', stdClass::class)],
            [new _StampingMiddleware()],
        );

        $result = $gate->evaluate(new ServerRequest('GET', '/ws/secure'));

        self::assertTrue($result->isAuthorized());
        self::assertNotNull($result->request);
        self::assertSame('user-42', $result->request->getAttribute('principal'));
    }

    #[Test]
    public function router_attributes_and_path_params_are_stamped_like_http_routing(): void
    {
        $observer = new _ObservingMiddleware();
        $gate = $this->gate(
            [WebSocketRoute::channel('/chat/{room}', stdClass::class, 'room', null, [$observer])],
        );

        $result = $gate->evaluate(new ServerRequest('GET', '/chat/lobby'));

        self::assertTrue($result->isAuthorized());
        self::assertTrue((bool) ($observer->seen['_nexus.routed'] ?? false));
        self::assertSame(stdClass::class, $observer->seen['_resolvedHandlerClass'] ?? null);
        self::assertSame('lobby', $observer->seen['room'] ?? null);
        self::assertNotNull($result->request);
        self::assertSame('lobby', $result->request->getAttribute('room'));
    }

    #[Test]
    public function per_route_middleware_runs_after_global_middleware(): void
    {
        $observer = new _ObservingMiddleware();
        $gate = $this->gate(
            [WebSocketRoute::handler('/ws/secure', stdClass::class, [$observer])],
            [new _StampingMiddleware()],
        );

        $result = $gate->evaluate(new ServerRequest('GET', '/ws/secure'));

        self::assertTrue($result->isAuthorized());
        // The per-route observer must see the principal the global middleware stamped.
        self::assertSame('user-42', $observer->seen['principal'] ?? null);
    }

    #[Test]
    public function middleware_returning_101_without_delegation_does_not_authorize(): void
    {
        $gate = $this->gate(
            [WebSocketRoute::handler('/ws/secure', stdClass::class)],
            [new _Fake101Middleware()],
        );

        $result = $gate->evaluate(new ServerRequest('GET', '/ws/secure'));

        self::assertFalse($result->isAuthorized());
        self::assertNotNull($result->rejection);
    }

    /**
     * @param list<WebSocketRoute> $routes
     * @param list<MiddlewareInterface> $global
     */
    private function gate(array $routes, array $global = []): HandshakeGate
    {
        return new HandshakeGate(WebSocketRouter::build($routes), $global, new MiddlewareResolver(null));
    }
}
