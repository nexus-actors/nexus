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
use Swoole\Coroutine\Http\Client;

use function Co\run;

#[CoversNothing]
final class WorkerModeHttpIntegrationTest extends TestCase
{
    #[Test]
    public function serves_compiled_http_app(): void
    {
        $port    = ForkedSwooleServerFixture::findFreePort();
        $fixture = new ForkedSwooleServerFixture('127.0.0.1', $port);

        $fixture->start(static function () use ($port): void {
            SwooleWorkerServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false),
                factory: static function (ActorSystem $system): CompiledApplication {
                    $app = HttpApplication::create($system);
                    $app->get('/hello', static fn(): ResponseInterface => Response::ok());

                    return $app->compile();
                },
            );
        });

        try {
            $statusCode = null;
            run(static function () use ($port, &$statusCode): void {
                $client = new Client('127.0.0.1', $port);
                $client->get('/hello');
                $statusCode = $client->statusCode;
                $client->close();
            });

            self::assertSame(200, $statusCode);
        } finally {
            $fixture->shutdown();
        }
    }
}
