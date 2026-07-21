<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\WebSocket;

use Monadial\Nexus\Http\Middleware\MiddlewareInvoker;
use Monadial\Nexus\Http\Middleware\MiddlewareResolver;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @psalm-api
 *
 * Pre-upgrade authorization gate for WebSocket connections.
 *
 * Runs the WebSocket route's PSR-15 middleware pipeline (global WS middleware
 * first, then per-route middleware) against the HTTP upgrade request BEFORE
 * the 101 protocol switch. The same middleware used on HTTP routes works
 * unchanged: the gate stamps `_nexus.routed` and `_resolvedHandlerClass`
 * exactly like RouterMiddleware does, so AuthenticationMiddleware resolves the
 * principal and AuthorizationMiddleware enforces #[RequiresAuth] /
 * #[RequiresScope] / #[RequiresRole] attributes declared on the WebSocket
 * handler or channel-actor class.
 *
 * A pipeline that completes yields the authorized upgrade request — including
 * the `principal` attribute — which the server passes to dispatchOpen so
 * #[FromPrincipal] parameters resolve after the upgrade. A pipeline that
 * short-circuits (401/403/...) yields a rejection response the server must
 * send instead of upgrading. Unmatched paths are rejected with 404 before any
 * upgrade happens.
 */
final readonly class HandshakeGate
{
    private LoggerInterface $logger;

    /**
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $globalMiddleware
     */
    public function __construct(
        private WebSocketRouter $router,
        private array $globalMiddleware,
        private MiddlewareResolver $resolver,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function evaluate(ServerRequestInterface $upgrade): HandshakeResult
    {
        $path = $upgrade->getUri()->getPath();
        $match = $this->router->match($path);

        if ($match === null) {
            $this->logger->debug('ws.handshake.rejected', ['path' => $path, 'status' => 404]);

            return HandshakeResult::rejected(new Response(404, [], 'No WebSocket route'));
        }

        $route = $match['route'];

        // Same attributes RouterMiddleware stamps on HTTP requests, so the
        // shared auth middleware behaves identically on the upgrade request.
        $request = $upgrade
            ->withAttribute('_nexus.routed', true)
            ->withAttribute('_resolvedHandlerClass', $route->targetClass);

        foreach ($match['params'] as $paramName => $paramValue) {
            $request = $request->withAttribute($paramName, $paramValue);
        }

        $stack = [];

        foreach ([...$this->globalMiddleware, ...$route->middleware] as $entry) {
            $stack[] = $entry instanceof MiddlewareInterface
                ? $entry
                : $this->resolver->resolve($entry);
        }

        $authorized = null;
        $tail = static function (ServerRequestInterface $final) use (&$authorized): ResponseInterface {
            $authorized = $final;

            return new Response(101);
        };

        $response = new MiddlewareInvoker($stack, $tail)->handle($request);

        // Fail closed: only the tail produces 101. A middleware returning 101
        // without calling the next handler never authorizes the upgrade.

        if ($response->getStatusCode() !== 101 || $authorized === null) {
            $this->logger->debug('ws.handshake.rejected', [
                'path' => $path,
                'status' => $response->getStatusCode(),
            ]);

            return HandshakeResult::rejected($response);
        }

        return HandshakeResult::authorized($authorized);
    }
}
