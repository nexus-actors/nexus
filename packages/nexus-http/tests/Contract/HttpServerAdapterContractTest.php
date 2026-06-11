<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Contract;

use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\HttpServerAdapter;
use Monadial\Nexus\Runtime\Duration;
use Nyholm\Psr7\Stream;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Concrete server adapter packages extend this and implement createAdapter()
 * + bind/connect helpers for their server type. The shared tests below
 * verify the adapter honours the contract.
 */
abstract class HttpServerAdapterContractTest extends TestCase
{
    abstract protected function createAdapter(): HttpServerAdapter;

    /**
     * Returns (host, port) the adapter binds to.
     *
     * @return array{0: string, 1: int}
     */
    abstract protected function bindAddress(): array;

    /** Send an HTTP request and return the raw body string. */
    abstract protected function sendGet(string $path): string;

    #[Test]
    public function adapter_handles_request_and_writes_response(): void
    {
        $app = new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $r): ResponseInterface
            {
                return Response::ok()->withBody(Stream::create('hello'));
            }
        };

        $adapter = $this->createAdapter();
        $adapter->serve($app);

        $body = $this->sendGet('/');
        self::assertSame('hello', $body);

        $adapter->shutdown(Duration::seconds(1));
    }
}
