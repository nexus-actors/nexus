<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Toolkit\Tests\Unit\Middleware;

use Monadial\Nexus\Http\Toolkit\Middleware\OriginAllowlistMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(OriginAllowlistMiddleware::class)]
final class OriginAllowlistMiddlewareTest extends TestCase
{
    #[Test]
    public function allows_exact_matching_origin(): void
    {
        $mw = new OriginAllowlistMiddleware(['https://app.example.com']);

        $response = $mw->process($this->request('POST', 'https://app.example.com'), $this->passHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function rejects_disallowed_origin(): void
    {
        $mw = new OriginAllowlistMiddleware(['https://app.example.com']);

        $response = $mw->process($this->request('POST', 'https://evil.example.com'), $this->passHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function rejects_suffix_lookalike_origin(): void
    {
        $mw = new OriginAllowlistMiddleware(['https://app.example.com']);

        // Substring/suffix attack: must not match on a naive contains check.
        $response = $mw->process(
            $this->request('POST', 'https://app.example.com.evil.com'),
            $this->passHandler(),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function rejects_scheme_mismatch(): void
    {
        $mw = new OriginAllowlistMiddleware(['https://app.example.com']);

        $response = $mw->process($this->request('POST', 'http://app.example.com'), $this->passHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function rejects_port_mismatch(): void
    {
        $mw = new OriginAllowlistMiddleware(['https://app.example.com']);

        $response = $mw->process($this->request('POST', 'https://app.example.com:8443'), $this->passHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function missing_origin_allowed_by_default(): void
    {
        $mw = new OriginAllowlistMiddleware(['https://app.example.com']);

        $response = $mw->process($this->request('POST', null), $this->passHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function missing_origin_rejected_when_required(): void
    {
        $mw = new OriginAllowlistMiddleware(['https://app.example.com'], allowMissingOrigin: false);

        $response = $mw->process($this->request('POST', null), $this->passHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function null_literal_origin_is_rejected(): void
    {
        // Browsers send `Origin: null` from sandboxed iframes / opaque origins.
        $mw = new OriginAllowlistMiddleware(['https://app.example.com'], allowMissingOrigin: false);

        $response = $mw->process($this->request('POST', 'null'), $this->passHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function websocket_upgrade_get_is_checked(): void
    {
        // WS upgrades arrive as GET and must be Origin-checked (CSWSH). GET is
        // not exempt by default.
        $mw = new OriginAllowlistMiddleware(['https://app.example.com']);

        $response = $mw->process($this->request('GET', 'https://evil.example.com'), $this->passHandler());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function configured_safe_method_is_exempt(): void
    {
        $mw = new OriginAllowlistMiddleware(['https://app.example.com'], safeMethods: ['GET']);

        $response = $mw->process($this->request('GET', 'https://evil.example.com'), $this->passHandler());

        self::assertSame(200, $response->getStatusCode());
    }

    private function request(string $method, ?string $origin): ServerRequestInterface
    {
        $request = (new Psr17Factory())->createServerRequest($method, 'https://app.example.com/x');

        return $origin === null
            ? $request
            : $request->withHeader('Origin', $origin);
    }

    private function passHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
