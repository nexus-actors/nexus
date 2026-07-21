<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerServer;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Tests\Integration\HttpSwoole\Support\ForkedSwooleServerFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Coroutine\Http\Client;

use function Co\run;
use function str_repeat;

/**
 * SEC-009: oversized requests are rejected by Swoole's native protocol limit
 * (package_max_length) before PHP materializes the body — for POST bodies with
 * a known Content-Length and for GET requests carrying a body. Bodies within
 * the cap are served normally.
 */
#[CoversNothing]
final class WorkerModeRequestLimitTest extends TestCase
{
    private const int LIMIT = 4096;

    /** Well past any Swoole protocol-buffer floor so the cap is unambiguously exceeded. */
    private const int OVERSIZED = 1024 * 1024;

    private static ?ForkedSwooleServerFixture $fixture = null;

    private static int $port = 0;

    #[Test]
    public function post_body_within_limit_is_served(): void
    {
        self::assertSame(200, $this->postStatus('/echo', str_repeat('a', self::LIMIT - 512)));
    }

    #[Test]
    public function oversized_post_body_is_rejected_before_the_handler(): void
    {
        // A body well past the cap: Swoole closes/492s the connection at the
        // protocol parser. The client observes a non-200 (or a hard close ->
        // statusCode <= 0), never the handler's 200.
        $status = $this->postStatus('/echo', str_repeat('a', self::OVERSIZED));

        self::assertNotSame(200, $status);
    }

    #[Test]
    public function oversized_get_body_is_rejected(): void
    {
        $status = $this->requestStatus('GET', '/get', str_repeat('a', self::OVERSIZED));

        self::assertNotSame(200, $status);
    }

    public static function setUpBeforeClass(): void
    {
        self::$port = ForkedSwooleServerFixture::findFreePort();
        self::$fixture = new ForkedSwooleServerFixture('127.0.0.1', self::$port);

        $port = self::$port;
        self::$fixture->start(static function () use ($port): void {
            SwooleWorkerServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false)
                    ->maxRequestBodyBytes(self::LIMIT),
                factory: static function (ActorSystem $system): CompiledApplication {
                    $app = HttpApplication::create($system);
                    $app->post('/echo', static function (ServerRequestInterface $r): ResponseInterface {
                        return Response::ok();
                    });
                    $app->get('/get', static fn(): ResponseInterface => Response::ok());

                    return $app->compile();
                },
            );
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixture?->shutdown();
        self::$fixture = null;
    }

    private function postStatus(string $path, string $body): int
    {
        return $this->requestStatus('POST', $path, $body);
    }

    private function requestStatus(string $method, string $path, string $body): int
    {
        $status = 0;

        run(static function () use ($method, $path, $body, &$status): void {
            $client = new Client('127.0.0.1', self::$port);
            $client->setMethod($method);
            $client->setData($body);
            $client->execute($path);
            $status = $client->statusCode;
            $client->close();
        });

        return $status;
    }
}
