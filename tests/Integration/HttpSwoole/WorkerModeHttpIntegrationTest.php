<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerConfig;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleWorkerHttpServer;
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
            SwooleWorkerHttpServer::run(
                config: SwooleWorkerConfig::bind('127.0.0.1', $port)
                    ->workers(1)
                    ->installSignalHandlers(false),
                factory: static function (ActorSystem $system): CompiledHttpApp {
                    $app = HttpApp::create($system);
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
