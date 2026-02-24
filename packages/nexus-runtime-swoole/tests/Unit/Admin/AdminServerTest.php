<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit\Admin;

use Monadial\Nexus\Runtime\Swoole\Admin\AdminServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

use function Swoole\Coroutine\run;

#[CoversClass(AdminServer::class)]
final class AdminServerTest extends TestCase
{
    #[Test]
    public function start_and_shutdown_lifecycle(): void
    {
        /** @var string|null $responseBody */
        $responseBody = null;

        /** @var int|null $statusCode */
        $statusCode = null;

        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (&$responseBody, &$statusCode): void {
            $server = new AdminServer('127.0.0.1', 19877);

            Coroutine::create(static function () use ($server): void {
                $server->start();
            });

            // Give server time to start
            Coroutine::sleep(0.05);

            $client = new Client('127.0.0.1', 19877);
            $client->get('/api/get_version_info');

            /** @var int $statusCode */
            $statusCode = $client->statusCode;

            /** @var string $responseBody */
            $responseBody = $client->body;
            $client->close();

            $server->shutdown();
        });

        self::assertSame(200, $statusCode);
        self::assertIsString($responseBody);

        /** @var array{code: int, data: array{php: string}} $decoded */
        $decoded = json_decode($responseBody, true);
        self::assertSame(0, $decoded['code']);
        self::assertSame(PHP_VERSION, $decoded['data']['php']);
    }

    #[Test]
    public function responds_with_cors_headers(): void
    {
        /** @var array<string, string>|null $headers */
        $headers = null;

        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (&$headers): void {
            $server = new AdminServer('127.0.0.1', 19878);

            Coroutine::create(static function () use ($server): void {
                $server->start();
            });

            Coroutine::sleep(0.05);

            $client = new Client('127.0.0.1', 19878);
            $client->get('/api/gc_status');

            /** @var array<string, string> $headers */
            $headers = $client->headers;
            $client->close();

            $server->shutdown();
        });

        self::assertIsArray($headers);
        self::assertSame('*', $headers['access-control-allow-origin']);
    }

    #[Test]
    public function unknown_command_returns_error_code(): void
    {
        /** @var string|null $responseBody */
        $responseBody = null;

        /** @psalm-suppress UnusedFunctionCall */
        run(static function () use (&$responseBody): void {
            $server = new AdminServer('127.0.0.1', 19879);

            Coroutine::create(static function () use ($server): void {
                $server->start();
            });

            Coroutine::sleep(0.05);

            $client = new Client('127.0.0.1', 19879);
            $client->get('/api/nonexistent');

            /** @var string $responseBody */
            $responseBody = $client->body;
            $client->close();

            $server->shutdown();
        });

        self::assertIsString($responseBody);

        /** @var array{code: int, data: string} $decoded */
        $decoded = json_decode($responseBody, true);
        self::assertSame(4004, $decoded['code']);
    }
}
