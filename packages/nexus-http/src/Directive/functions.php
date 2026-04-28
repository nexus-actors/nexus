<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http;

// Directive functions are appended to this file by the tasks that follow.
// All directives live in this single namespace and are autoloaded as composer "files".

use Closure;
use LogicException;
use Monadial\Nexus\Http\Extract\Extractor;
use Monadial\Nexus\Http\Marshalling\MediaType;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use Monadial\Nexus\Http\Routing\Route;
use Monadial\Nexus\Runtime\Async\Future;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function array_pop;
use function class_exists;
use function is_callable;
use function is_string;
use function parse_str;

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

/**
 * Match a single literal path segment, then delegate to the child route.
 *
 * @param Closure(): Route $child
 */
function pathPrefix(string $literal, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($literal, $child): ?ResponseInterface {
        $next = $ctx->pathState()->consume($literal);

        if ($next === null) {
            return null;
        }

        $route = $child();

        return ($route->run)($ctx->withPathState($next));
    });
}

/**
 * Require the path to be fully consumed before delegating.
 *
 * @param Closure(): Route $child
 */
function pathEnd(Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($child): ?ResponseInterface {
        if (!$ctx->pathState()->isEmpty()) {
            return null;
        }

        $route = $child();

        return ($route->run)($ctx);
    });
}

/**
 * Match a sequence of literal and/or extracted path segments.
 *
 * Last argument MUST be a callable that takes the extracted values (in order)
 * and returns a Route. Earlier arguments are either string literals or
 * class-strings of Extractor implementations.
 *
 * @psalm-suppress MixedAssignment $args is intentionally mixed
 * @psalm-suppress MixedArgument segments and child are validated at runtime
 * @psalm-suppress MixedFunctionCall $child is validated via is_callable
 * @psalm-suppress MixedMethodCall $instance is validated via class_exists
 * @psalm-suppress MixedPropertyFetch $route returned by $child is opaque to Psalm
 * @psalm-suppress MixedReturnStatement $route returned by $child is opaque to Psalm
 */
function path(mixed ...$args): Route
{
    if ($args === []) {
        throw new LogicException('path() requires at least one segment and a child callable');
    }

    /** @var mixed $child */
    $child = array_pop($args);

    if (!is_callable($child)) {
        throw new LogicException('path() last argument must be callable');
    }

    /** @var list<mixed> $segments */
    $segments = $args;

    return new Route(static function (RequestCtx $ctx) use ($segments, $child): ?ResponseInterface {
        $state = $ctx->pathState();
        /** @var list<mixed> $extracted */
        $extracted = [];

        foreach ($segments as $segment) {
            $consume = $state->consumeAny();

            if ($consume === null) {
                return null;
            }

            [$current, $next] = $consume;

            if (is_string($segment) && !class_exists($segment)) {
                if ($segment !== $current) {
                    return null;
                }
            } else {
                /** @var class-string<Extractor<mixed>> $segment */
                $instance = new $segment();
                /** @var mixed $value */
                $value = $instance->fromSegment($current);
                $extracted[] = $value;
            }

            $state = $next;
        }

        $route = $child(...$extracted);

        return ($route->run)($ctx->withPathState($state));
    });
}

/**
 * Extract a required query parameter; rejects (returns null) when missing.
 *
 * @param ?class-string<Extractor> $extractorClass
 * @param Closure(mixed): Route $child
 *
 * @psalm-suppress MixedAssignment $resolved is intentionally mixed
 * @psalm-suppress MixedMethodCall extractor instantiation is class-string-validated
 */
function query(string $name, ?string $extractorClass, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($name, $extractorClass, $child): ?ResponseInterface {
        $params = $ctx->request()->getQueryParams();

        if (!isset($params[$name]) || !is_string($params[$name])) {
            return null;
        }

        $value = $params[$name];
        /** @var mixed $resolved */
        $resolved = $extractorClass !== null
            ? (new $extractorClass())->fromSegment($value)
            : $value;

        $route = $child($resolved);

        return ($route->run)($ctx);
    });
}

/**
 * Extract an optional query parameter; passes null to child when missing.
 *
 * @param ?class-string<Extractor> $extractorClass
 * @param Closure(mixed): Route $child
 *
 * @psalm-suppress MixedAssignment $resolved is intentionally mixed
 * @psalm-suppress MixedMethodCall extractor instantiation is class-string-validated
 */
function optionalQuery(string $name, ?string $extractorClass, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($name, $extractorClass, $child): ?ResponseInterface {
        $params = $ctx->request()->getQueryParams();
        $raw = isset($params[$name]) && is_string($params[$name])
            ? $params[$name]
            : null;

        /** @var mixed $resolved */
        $resolved = match (true) {
            $raw === null => null,
            $extractorClass === null => $raw,
            default => (new $extractorClass())->fromSegment($raw),
        };

        $route = $child($resolved);

        return ($route->run)($ctx);
    });
}

/**
 * Extract a required header; rejects (returns null) when absent or empty.
 *
 * @param Closure(string): Route $child
 */
function header(string $name, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($name, $child): ?ResponseInterface {
        $value = $ctx->request()->getHeaderLine($name);

        if ($value === '') {
            return null;
        }

        $route = $child($value);

        return ($route->run)($ctx);
    });
}

/**
 * Extract an optional header; passes null to child when absent or empty.
 *
 * @param Closure(?string): Route $child
 */
function optionalHeader(string $name, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($name, $child): ?ResponseInterface {
        $value = $ctx->request()->getHeaderLine($name);
        $resolved = $value === ''
            ? null
            : $value;

        $route = $child($resolved);

        return ($route->run)($ctx);
    });
}

/**
 * Pass the raw PSR-7 ServerRequest to the child.
 *
 * @param Closure(ServerRequestInterface): Route $child
 */
function extractRequest(Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($child): ?ResponseInterface {
        $route = $child($ctx->request());

        return ($route->run)($ctx);
    });
}

/**
 * Pass the raw request body string to the child.
 *
 * @param Closure(string): Route $child
 */
function rawBody(Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($child): ?ResponseInterface {
        $body = (string) $ctx->request()->getBody();
        $route = $child($body);

        return ($route->run)($ctx);
    });
}

/**
 * Unmarshal the JSON body into the target type and pass to the child.
 *
 * @template T of object
 * @param class-string<T> $targetType
 * @param Closure(T): Route $child
 *
 * @psalm-suppress MixedArgument Marshaller::unmarshal returns T but Psalm widens it across the closure boundary
 */
function jsonBody(string $targetType, Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($targetType, $child): ?ResponseInterface {
        $body = (string) $ctx->request()->getBody();
        $marshaller = $ctx->marshallerFor(MediaType::parse('application/json'));
        $value = $marshaller->unmarshal($body, $targetType);
        $route = $child($value);

        return ($route->run)($ctx);
    });
}

/**
 * Parse the application/x-www-form-urlencoded body and pass the resulting array to the child.
 *
 * @param Closure(array<string, mixed>): Route $child
 *
 * @psalm-suppress MixedArgumentTypeCoercion parse_str narrows $parsed at runtime to array<string, mixed>
 */
function formBody(Closure $child): Route
{
    return new Route(static function (RequestCtx $ctx) use ($child): ?ResponseInterface {
        $body = (string) $ctx->request()->getBody();
        $parsed = [];
        parse_str($body, $parsed);

        $route = $child($parsed);

        return ($route->run)($ctx);
    });
}
