<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Http;

use Monadial\Nexus\Symfony\Http\SwooleHttpBridge;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(SwooleHttpBridge::class)]
final class SwooleHttpBridgeTest extends TestCase
{
    #[Test]
    public function toSymfonyRequestBuildsCorrectRequest(): void
    {
        $bridge = new SwooleHttpBridge();

        $swooleRequest = $this->createSwooleRequestStub(
            server: [
                'request_method' => 'POST',
                'request_uri'    => '/orders',
            ],
            header: ['content-type' => 'application/json'],
            body: '{"id":1}',
        );

        $request = $bridge->toSymfonyRequest($swooleRequest);

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/orders', $request->getPathInfo());
        self::assertSame('{"id":1}', $request->getContent());
    }

    #[Test]
    public function sendSymfonyResponseWritesStatusAndBody(): void
    {
        $bridge   = new SwooleHttpBridge();
        $response = new Response('Hello', 201, ['X-Custom' => 'value']);

        $swooleResponse = $this->createMock(\Swoole\Http\Response::class);
        $swooleResponse->expects($this->once())->method('status')->with(201);
        $swooleResponse->expects($this->once())->method('end')->with('Hello');

        $bridge->sendSymfonyResponse($response, $swooleResponse);
    }

    private function createSwooleRequestStub(
        array $server,
        array $header,
        string $body,
    ): \Swoole\Http\Request {
        $stub         = $this->createStub(\Swoole\Http\Request::class);
        $stub->server = $server;
        $stub->header = $header;
        $stub->get    = [];
        $stub->post   = [];
        $stub->cookie = [];
        $stub->files  = [];
        $stub->method('rawContent')->willReturn($body);

        return $stub;
    }
}
