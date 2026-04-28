<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Extract\IntNumber;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\extractRequest;
use function Monadial\Nexus\Http\header;
use function Monadial\Nexus\Http\optionalHeader;
use function Monadial\Nexus\Http\optionalQuery;
use function Monadial\Nexus\Http\query;

final class QueryHeaderTest extends TestCase
{
    #[Test]
    public function query_passes_string_value(): void
    {
        $route = query('q', null, static fn(string $q) => complete(['q' => $q]));
        $response = ($route->run)($this->ctxWith('/?q=hello'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"q":"hello"', (string) $response->getBody());
    }

    #[Test]
    public function query_with_extractor_returns_typed(): void
    {
        $route = query('limit', IntNumber::class, static fn(int $n) => complete(['n' => $n]));
        $response = ($route->run)($this->ctxWith('/?limit=20'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"n":20', (string) $response->getBody());
    }

    #[Test]
    public function query_returns_null_when_missing(): void
    {
        $route = query('q', null, static fn(string $q) => complete(['q' => $q]));
        self::assertNull(($route->run)($this->ctxWith('/')));
    }

    #[Test]
    public function optional_query_passes_null_when_missing(): void
    {
        $route = optionalQuery('q', null, static fn(?string $q) => complete(['q' => $q]));
        $response = ($route->run)($this->ctxWith('/'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"q":null', (string) $response->getBody());
    }

    #[Test]
    public function header_extracts_header_value(): void
    {
        $route = header('X-Trace-Id', static fn(string $id) => complete(['id' => $id]));
        $response = ($route->run)($this->ctxWith('/', ['X-Trace-Id' => 'abc']));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"id":"abc"', (string) $response->getBody());
    }

    #[Test]
    public function optional_header_passes_null_when_missing(): void
    {
        $route = optionalHeader('X-Y', static fn(?string $v) => complete(['v' => $v]));
        $response = ($route->run)($this->ctxWith('/'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"v":null', (string) $response->getBody());
    }

    #[Test]
    public function extract_request_passes_psr7_server_request(): void
    {
        $route = extractRequest(static fn($req) => complete(['m' => $req->getMethod()]));
        $response = ($route->run)($this->ctxWith('/'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"m":"GET"', (string) $response->getBody());
    }

    /** @param array<string, string> $headers */
    private function ctxWith(string $uri, array $headers = []): DefaultRequestCtx
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return new DefaultRequestCtx(
            request: $request,
            params: [],
            system: ActorSystem::create('query-header-test', new StepRuntime()),
            registry: MarshallerRegistry::withDefaults(),
            logger: new NullLogger(),
        );
    }
}
