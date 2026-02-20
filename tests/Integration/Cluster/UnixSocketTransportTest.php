<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Cluster;

use Monadial\Nexus\Cluster\Swoole\Transport\UnixSocketTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

use function Swoole\Coroutine\run;

#[CoversClass(UnixSocketTransport::class)]
#[RequiresPhpExtension('swoole')]
final class UnixSocketTransportTest extends TestCase
{
    private string $socketDir;

    #[Test]
    public function sendAndReceiveBetweenTwoWorkers(): void
    {
        $received = [];

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$received): void {
            $transport0 = new UnixSocketTransport(0, 2, $this->socketDir);
            $transport1 = new UnixSocketTransport(1, 2, $this->socketDir);

            $transport0->bind();
            $transport1->bind();

            $transport1->listen(static function (string $data) use (&$received): void {
                $received[] = $data;
            });

            $transport0->connectToPeers();
            $transport1->connectToPeers();

            $transport0->send(1, 'hello from worker 0');

            Coroutine::sleep(0.1);

            $transport0->close();
            $transport1->close();
        });

        self::assertCount(1, $received);
        self::assertSame('hello from worker 0', $received[0]);
    }

    #[Test]
    public function multipleMessagesDeliveredInOrder(): void
    {
        $received = [];

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$received): void {
            $transport0 = new UnixSocketTransport(0, 2, $this->socketDir);
            $transport1 = new UnixSocketTransport(1, 2, $this->socketDir);

            $transport0->bind();
            $transport1->bind();

            $transport1->listen(static function (string $data) use (&$received): void {
                $received[] = $data;
            });

            $transport0->connectToPeers();
            $transport1->connectToPeers();

            for ($i = 0; $i < 10; $i++) {
                $transport0->send(1, "msg-{$i}");
            }

            Coroutine::sleep(0.2);

            $transport0->close();
            $transport1->close();
        });

        self::assertCount(10, $received);

        for ($i = 0; $i < 10; $i++) {
            self::assertSame("msg-{$i}", $received[$i]);
        }
    }

    #[Test]
    public function bidirectionalCommunication(): void
    {
        $received0 = [];
        $received1 = [];

        /** @psalm-suppress UnusedFunctionCall */
        run(function () use (&$received0, &$received1): void {
            $transport0 = new UnixSocketTransport(0, 2, $this->socketDir);
            $transport1 = new UnixSocketTransport(1, 2, $this->socketDir);

            $transport0->bind();
            $transport1->bind();

            $transport0->listen(static function (string $data) use (&$received0): void {
                $received0[] = $data;
            });
            $transport1->listen(static function (string $data) use (&$received1): void {
                $received1[] = $data;
            });

            $transport0->connectToPeers();
            $transport1->connectToPeers();

            $transport0->send(1, 'hello-1');
            $transport1->send(0, 'hello-0');

            Coroutine::sleep(0.1);

            $transport0->close();
            $transport1->close();
        });

        self::assertCount(1, $received0);
        self::assertSame('hello-0', $received0[0]);
        self::assertCount(1, $received1);
        self::assertSame('hello-1', $received1[0]);
    }

    protected function setUp(): void
    {
        $this->socketDir = sys_get_temp_dir() . '/nexus-test-' . getmypid();

        if (!is_dir($this->socketDir)) {
            mkdir($this->socketDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        $files = glob($this->socketDir . '/*.sock');

        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        @rmdir($this->socketDir);
    }
}
