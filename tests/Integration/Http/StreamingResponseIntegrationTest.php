<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\StreamingResponse;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversNothing]
final class StreamingResponseIntegrationTest extends TestCase
{
    #[Test]
    public function ndjson_streams_through_handler(): void
    {
        $system = ActorSystem::create('test-http', new FiberRuntime());
        $app = HttpApp::create($system);
        $app->get(
            '/items',
            static fn(ServerRequestInterface $r): ResponseInterface => StreamingResponse::ndjson([
                ['id' => 1],
                ['id' => 2],
            ]),
        );

        $response = $app->compile()->handle(new ServerRequest('GET', '/items'));

        self::assertSame('application/x-ndjson', $response->getHeaderLine('Content-Type'));
        $body = $response->getBody();
        $first = $body->read(1024);
        $second = $body->read(1024);
        self::assertSame("{\"id\":1}\n", $first);
        self::assertSame("{\"id\":2}\n", $second);
    }
}
