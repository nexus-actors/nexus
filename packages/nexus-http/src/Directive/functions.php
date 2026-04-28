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
 *
 * @psalm-suppress MixedAssignment $value is intentionally mixed
 * @psalm-suppress MixedFunctionCall callable form invokes $value with ctx
 */
function complete(mixed $value, int $status = 200): Route
{
    return new Route(static function (RequestCtx $ctx) use ($value, $status): ResponseInterface {
        /** @var mixed $resolved */
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

/**
 * Complete with an explicit PSR-7 Response.
 *
 * @psalm-suppress UnusedClosureParam
 */
function completeWith(ResponseInterface $response): Route
{
    return new Route(static fn(RequestCtx $ctx): ResponseInterface => $response);
}

/**
 * Complete with a builder closure that constructs the Response.
 *
 * @param Closure(RequestCtx): ResponseInterface $build
 */
function completeBuilt(Closure $build): Route
{
    return new Route(static fn(RequestCtx $ctx): ResponseInterface => $build($ctx));
}

/**
 * Issue a redirect (defaults to 302 Found).
 *
 * @psalm-suppress UnusedClosureParam
 */
function redirect(string $location, int $status = 302): Route
{
    return new Route(
        static fn(RequestCtx $ctx): ResponseInterface => (new Response($status))->withHeader('Location', $location),
    );
}

/**
 * Throw a rejection — caught by the surrounding error mapper.
 *
 * @psalm-suppress UnusedClosureParam
 */
function reject(RouteRejection $rejection): Route
{
    return new Route(static function (RequestCtx $ctx) use ($rejection): never {
        throw $rejection;
    });
}

/**
 * Generic HTTP method directive — matches the request method against $verb,
 * delegates to the child route on match, rejects (returns null) otherwise.
 *
 * @param Closure(): Route $child
 */
function method(string $verb, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($verb, $child): ?ResponseInterface {
        if ($ctx->request()->getMethod() !== $verb) {
            return null;
        }

        $next = $child();

        return ($next->run)($ctx);
    });
}

/**
 * Match GET requests.
 *
 * @param Closure(): Route $child
 */
function get(Closure $child): Route
{
    return method('GET', $child);
}

/**
 * Match POST requests.
 *
 * @param Closure(): Route $child
 */
function post(Closure $child): Route
{
    return method('POST', $child);
}

/**
 * Match PUT requests.
 *
 * @param Closure(): Route $child
 */
function put(Closure $child): Route
{
    return method('PUT', $child);
}

/**
 * Match DELETE requests.
 *
 * @param Closure(): Route $child
 */
function delete(Closure $child): Route
{
    return method('DELETE', $child);
}

/**
 * Match PATCH requests.
 *
 * @param Closure(): Route $child
 */
function patch(Closure $child): Route
{
    return method('PATCH', $child);
}

/**
 * Concatenate routes — try each in order, return the first non-null response.
 * Returns null if all children reject (or no children given).
 */
function concat(Route ...$routes): Route
{
    return new Route(static function (RequestCtx $ctx) use ($routes): ?ResponseInterface {
        foreach ($routes as $route) {
            $response = ($route->run)($ctx);

            if ($response !== null) {
                return $response;
            }
        }

        return null;
    });
}
