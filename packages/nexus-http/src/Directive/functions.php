<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

// Directive functions are appended to this file by the tasks that follow.
// All directives live in this single namespace and are autoloaded as composer "files".

use Closure;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Runtime\Async\Future;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;

use function is_callable;

/**
 * Complete a request with a value (auto-marshalled by the negotiated marshaller).
 *
 * Resolution order:
 * - If $value is callable, it is invoked with RequestCtx (lazy evaluation).
 * - If the resolved value is a Future, it is awaited (yields the coroutine until ready).
 * - The final value is then marshalled.
 */
function complete(mixed $value, int $status = 200): Route
{
    return new Route(static function (RequestCtx $ctx) use ($value, $status): ResponseInterface {
        $resolved = is_callable($value)
            ? $value($ctx)
            : $value;

        if ($resolved instanceof Future) {
            $resolved = $resolved->await();
        }

        $marshaller = $ctx->marshallerFor($ctx->negotiate());
        $body = $marshaller->marshal($resolved);

        return (new Response($status))
            ->withHeader('Content-Type', (string) $marshaller->mediaType())
            ->withBody(Stream::create($body));
    });
}

/** Complete with an explicit PSR-7 Response. */
function completeWith(ResponseInterface $response): Route
{
    return new Route(static fn(RequestCtx $ctx): ResponseInterface => $response);
}

/** Complete with a builder closure that constructs the Response. */
function completeBuilt(Closure $build): Route
{
    return new Route(static fn(RequestCtx $ctx): ResponseInterface => $build($ctx));
}

/** Issue a redirect (defaults to 302 Found). */
function redirect(string $location, int $status = 302): Route
{
    return new Route(
        static fn(RequestCtx $ctx): ResponseInterface => (new Response($status))->withHeader('Location', $location),
    );
}

/** Throw a rejection — caught by the surrounding error mapper. */
function reject(RouteRejection $rejection): Route
{
    return new Route(static function (RequestCtx $ctx) use ($rejection): never {
        throw $rejection;
    });
}
