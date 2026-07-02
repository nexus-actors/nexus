<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\HttpSwoole;

use Monadial\Nexus\Http\Server\HttpServerAdapter;
use Monadial\Nexus\Http\Server\Swoole\Server\SwooleHttpServerAdapter;
use Monadial\Nexus\Http\Tests\Contract\HttpServerAdapterContractTest;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Coroutine\Http\Client;
use Swoole\Http\Server;
use Swoole\Process;

use function Co\run;

final class SwooleHttpServerAdapterContractTest extends HttpServerAdapterContractTest
{
    private int $port = 0;

    private ?Process $process = null;

    #[Override]
    protected function createAdapter(): HttpServerAdapter
    {
        $this->port = $this->findFreePort();
        $port = $this->port;

        // Wrap the adapter so serve() forks a child to run the blocking
        // Swoole event loop and shutdown() kills the child. This keeps the
        // base contract test (which calls serve() then sendGet() in sequence)
        // working unmodified.
        return new class ($port, $this->process) implements HttpServerAdapter {
            public function __construct(private readonly int $port, private ?Process &$process) {}

            #[Override]
            public function serve(RequestHandlerInterface $app): void
            {
                $port = $this->port;
                $this->process = new Process(static function () use ($port, $app): void {
                    $server = new Server('127.0.0.1', $port);
                    $server->set([
                        'log_file' => '/tmp/nexus-http-swoole-contract.log',
                        'log_level' => SWOOLE_LOG_NOTICE,
                        'worker_num' => 1,
                    ]);
                    (new SwooleHttpServerAdapter($server))->serve($app);
                });
                $this->process->start();

                // Wait briefly for the child to start listening.
                $deadline = microtime(true) + 5.0;

                while (microtime(true) < $deadline) {
                    $sock = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $_, $_, 0.1);

                    if ($sock !== false) {
                        fclose($sock);

                        return;
                    }

                    usleep(50_000);
                }
            }

            #[Override]
            public function shutdown(Duration $timeout): void
            {
                if ($this->process === null) {
                    return;
                }

                Process::kill($this->process->pid, SIGTERM);
                Process::wait();
                $this->process = null;
            }
        };
    }

    #[Override]
    protected function bindAddress(): array
    {
        return ['127.0.0.1', $this->port];
    }

    #[Override]
    protected function sendGet(string $path): string
    {
        $body = '';
        run(function () use ($path, &$body): void {
            $client = new Client('127.0.0.1', $this->port);
            $client->get($path);
            $body = $client->body;
            $client->close();
        });

        return $body;
    }

    private function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        $parts = explode(':', $name);

        return (int) end($parts);
    }
}
