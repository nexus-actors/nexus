<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Swoole;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Observability\Http\TelemetryServer;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

use function Swoole\Coroutine\run;

#[CoversClass(TelemetryServer::class)]
final class TelemetryServerTest extends TestCase
{
    #[Test]
    public function status_endpoint_returns_actor_hierarchy(): void
    {
        $captured = [];

        run(static function () use (&$captured): void {
            $runtime = new SwooleRuntime();
            $system  = ActorSystem::create('telemetry-test', $runtime);

            $system->spawn(
                Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
                'orders',
            );

            $server = new TelemetryServer($system, $runtime, port: 19502);
            $server->start();

            Coroutine::sleep(0.05);

            $client = new Client('127.0.0.1', 19502);
            $client->get('/status');
            $captured['status'] = $client->statusCode;
            $captured['body']   = json_decode($client->body, true);
            $client->close();

            $system->shutdown(Duration::seconds(1));
        });

        self::assertSame(200, $captured['status']);
        self::assertSame('standalone', $captured['body']['mode']);
        self::assertSame('telemetry-test', $captured['body']['system']['name']);
        self::assertCount(1, $captured['body']['system']['actors']);
        self::assertSame('/user/orders', $captured['body']['system']['actors'][0]['path']);
    }

    #[Test]
    public function metrics_endpoint_returns_prometheus_text(): void
    {
        $captured = [];

        run(static function () use (&$captured): void {
            $runtime = new SwooleRuntime();
            $system  = ActorSystem::create('prom-test', $runtime);

            $system->spawn(
                Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
                'payments',
            );

            $server = new TelemetryServer($system, $runtime, port: 19503);
            $server->start();

            Coroutine::sleep(0.05);

            $client = new Client('127.0.0.1', 19503);
            $client->get('/metrics');
            $captured['status'] = $client->statusCode;
            $captured['body']   = $client->body;
            $client->close();

            $system->shutdown(Duration::seconds(1));
        });

        self::assertSame(200, $captured['status']);
        self::assertStringContainsString('nexus_actor_mailbox_depth', $captured['body']);
        self::assertStringContainsString('/user/payments', $captured['body']);
        self::assertStringContainsString('nexus_coroutine_num', $captured['body']);
    }
}
