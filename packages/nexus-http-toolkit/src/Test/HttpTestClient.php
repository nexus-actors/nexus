<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Test;

use Monadial\Nexus\Http\Ws\CompiledApplication;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

use function http_build_query;
use function is_array;
use function is_string;
use function json_encode;

/**
 * @psalm-api
 *
 * In-process test client for Nexus HTTP applications. Builds a PSR-7
 * ServerRequest, dispatches it through the CompiledApplication, and
 * returns a TestResponse for fluent assertions — no socket, no Swoole.
 *
 * Drop-in for unit and integration tests:
 *
 *   $app = HttpApplication::create($system)
 *       ->get('/orders/{id}', ShowOrderHandler::class)
 *       ->compile();
 *
 *   $response = HttpTestClient::for($app)
 *       ->withHeader('Authorization', 'Bearer xyz')
 *       ->get('/orders/42');
 *
 *   $response->assertOk()->assertJsonPath('id', '42');
 *
 * Pair with StepRuntime for fully deterministic test runs: the actor
 * system runs step()/drain() between assertions, so request handling
 * doesn't depend on wall-clock or fiber scheduling.
 */
final class HttpTestClient
{
    private readonly Psr17Factory $factory;

    /** @var array<string, string> */
    private array $defaultHeaders = [];

    /** @var array<string, string> */
    private array $defaultCookies = [];

    private string $baseHost = 'localhost';

    private string $baseScheme = 'http';

    private function __construct(private readonly CompiledApplication $app)
    {
        $this->factory = new Psr17Factory();
    }

    public static function for(CompiledApplication $app): self
    {
        return new self($app);
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->defaultHeaders[$name] = $value;

        return $clone;
    }

    public function withBearerToken(string $token): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    public function withCookie(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->defaultCookies[$name] = $value;

        return $clone;
    }

    public function withBase(string $scheme, string $host): self
    {
        $clone = clone $this;
        $clone->baseScheme = $scheme;
        $clone->baseHost = $host;

        return $clone;
    }

    /** @param array<string, scalar|null> $query */
    public function get(string $path, array $query = []): TestResponse
    {
        return $this->dispatch('GET', $path, $query, null);
    }

    /** @param array<string, scalar|null> $query */
    public function delete(string $path, array $query = []): TestResponse
    {
        return $this->dispatch('DELETE', $path, $query, null);
    }

    /** @param array<string, mixed>|string|null $body */
    public function post(string $path, array|string|null $body = null): TestResponse
    {
        return $this->dispatch('POST', $path, [], $body);
    }

    /** @param array<string, mixed>|string|null $body */
    public function put(string $path, array|string|null $body = null): TestResponse
    {
        return $this->dispatch('PUT', $path, [], $body);
    }

    /** @param array<string, mixed>|string|null $body */
    public function patch(string $path, array|string|null $body = null): TestResponse
    {
        return $this->dispatch('PATCH', $path, [], $body);
    }

    /**
     * @param array<string, scalar|null> $query
     * @param array<string, mixed>|string|null $body
     */
    private function dispatch(string $method, string $path, array $query, array|string|null $body): TestResponse
    {
        $uri = $this->buildUri($path, $query);
        $request = $this->factory->createServerRequest($method, $uri);

        foreach ($this->defaultHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($this->defaultCookies !== []) {
            $request = $request->withCookieParams($this->defaultCookies);
        }

        if ($body !== null) {
            $request = $this->attachBody($request, $body);
        }

        $request = $request->withQueryParams($this->parseQueryArray($query));

        return new TestResponse($this->app->handle($request));
    }

    /** @param array<string, mixed>|string $body */
    private function attachBody(ServerRequestInterface $request, array|string $body): ServerRequestInterface
    {
        if (is_array($body)) {
            $encoded = (string) json_encode($body);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withParsedBody($body);
        } else {
            $encoded = $body;
        }

        return $request->withBody($this->stream($encoded));
    }

    private function stream(string $content): StreamInterface
    {
        return $this->factory->createStream($content);
    }

    /** @param array<string, scalar|null> $query */
    private function buildUri(string $path, array $query): UriInterface
    {
        $uri = $this->factory->createUri($path);

        if ($uri->getHost() === '') {
            $uri = $uri->withScheme($this->baseScheme)->withHost($this->baseHost);
        }

        if ($query !== []) {
            $uri = $uri->withQuery(http_build_query($query));
        }

        return $uri;
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, string>
     */
    private function parseQueryArray(array $query): array
    {
        $out = [];

        foreach ($query as $k => $v) {
            if ($v === null) {
                continue;
            }

            $out[$k] = is_string($v)
                ? $v
                : (string) $v;
        }

        return $out;
    }
}
