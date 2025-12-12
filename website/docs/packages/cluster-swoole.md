---
sidebar_position: 7
title: nexus-cluster-swoole
---

# nexus-cluster-swoole

Swoole-specific implementations for `nexus-cluster` interfaces. Provides
Unix domain socket transport, shared-memory actor directory, and the
`ClusterBootstrap` entry point.

**Namespace:** `Monadial\Nexus\Cluster\Swoole\`

**Requires:** Swoole PHP extension 5.0+

## Classes

### ClusterBootstrap

Entry point for starting a multi-process cluster. Creates a
`Swoole\Process\Pool` and manages the lifecycle of all worker processes.

```php
final class ClusterBootstrap
{
    public static function create(ClusterConfig $config): self;
    public function onWorkerStart(callable $callback): self;
    public function withSerializer(ClusterSerializer $serializer): self;
    public function run(): void;
}
```

Usage:

```php
ClusterBootstrap::create(ClusterConfig::withWorkers(8))
    ->onWorkerStart(function (ClusterNode $node): void {
        $node->spawn(Props::fromBehavior($behavior), 'my-actor');
    })
    ->run();
```

`run()` blocks until the process pool exits. Each worker process runs
independently with its own `SwooleRuntime`, `ActorSystem`, `UnixSocketTransport`,
and `ClusterNode`.

### UnixSocketTransport

Implements `Monadial\Nexus\Cluster\Transport\Transport`.

AF_UNIX domain socket transport for inter-worker IPC. Each worker creates a
server socket at `{socketDir}/worker-{id}.sock` and connects as a client to
all other workers.

Wire format: `[4 bytes: payload length (network byte order)][N bytes: payload]`

All reads and writes are non-blocking via Swoole coroutines.

```php
final class UnixSocketTransport implements Transport
{
    public function __construct(int $workerId, int $workerCount, string $socketDir);
    public function bind(): void;
    public function connectToPeers(): void;
    public function send(int $targetWorker, string $data): void;
    public function listen(callable $onMessage): void;
    public function close(): void;
}
```

Lifecycle: `bind()` creates the server socket and starts an accept coroutine.
After all workers bind, `connectToPeers()` establishes client connections to
every other worker. `listen()` registers the message callback. `close()` shuts
down all connections and removes the socket file.

### SwooleTableDirectory

Implements `Monadial\Nexus\Cluster\Directory\ActorDirectory`.

Shared-memory actor directory backed by `Swoole\Table`. The table is created
in the master process before forking and is shared across all worker processes
via shared memory -- no IPC overhead for directory lookups.

```php
final readonly class SwooleTableDirectory implements ActorDirectory
{
    public static function createTable(int $size): Table;
    public function __construct(Table $table);
    public function register(string $path, int $workerId): void;
    public function lookup(string $path): ?int;
    public function remove(string $path): void;
    public function has(string $path): bool;
}
```

`createTable()` creates a `Swoole\Table` with a single `worker_id` column.
Pass the same `Table` instance to each worker's `SwooleTableDirectory`.

### PhpNativeClusterSerializer

Implements `Monadial\Nexus\Cluster\Serialization\ClusterSerializer`.

Uses PHP's native `serialize()` / `unserialize()` for maximum IPC performance.
Benchmarked at 196K serialize+deserialize cycles/sec.

Suitable for same-machine clustering where all workers share the same codebase
and class definitions.
