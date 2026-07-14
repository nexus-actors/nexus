<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Otel\Tests\Unit\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Monadial\Nexus\Observability\Otel\Http\SwooleCoroutinePsr18Client;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(SwooleCoroutinePsr18Client::class)]
final class SwooleCoroutinePsr18ClientTest extends TestCase
{
    /**
     * Outside a Swoole coroutine (unit tests run on the plain event loop), the client
     * must delegate to the fallback PSR-18 client — coroutine hooks do not apply there
     * and `Swoole\Coroutine\Http\Client` cannot be used at all.
     */
    #[Test]
    public function delegatesToFallbackOutsideACoroutine(): void
    {
        $fallback = new class implements ClientInterface {
            public ?RequestInterface $seen = null;

            #[Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->seen = $request;

                return new Response(204);
            }
        };

        $client = new SwooleCoroutinePsr18Client(
            $fallback,
            Psr17FactoryDiscovery::findResponseFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
            5.0,
        );

        $request = new Request('POST', 'http://collector:4318/v1/traces');
        $response = $client->sendRequest($request);

        self::assertSame($request, $fallback->seen);
        self::assertSame(204, $response->getStatusCode());
    }
}
