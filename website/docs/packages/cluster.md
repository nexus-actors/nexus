---
sidebar_position: 6
title: nexus-cluster
---

# nexus-cluster

Pure PHP abstractions for multi-process scaling and future multi-server
clustering. Defines interfaces for transport, directory, and serialization,
plus the core routing logic. Has no dependency on Swoole or any specific
runtime.

**Composer:** `nexus-actors/cluster`

**Namespace:** `Monadial\Nexus\Cluster\`

## Classes

### ClusterConfig

Immutable configuration for the cluster topology.

```php
final readonly class ClusterConfig
{
    public static function withWorkers(
        int $workerCount,
        int $tableSize = 65536,
        string $socketDir = '',
    ): self;
}
```

| Property | Type | Description |
|---|---|---|
| `workerCount` | `int` | Number of worker processes. |
| `tableSize` | `int` | Actor directory capacity. |
| `socketDir` | `string` | Unix socket file directory. |

### ConsistentHashRing

Deterministic mapping from actor names to worker IDs. Uses crc32 with 150
virtual nodes per worker.

```php
final readonly class ConsistentHashRing
{
    public function __construct(int $workerCount, int $virtualNodes = 150);
    public function getWorker(string $name): int;
}
```

The same name always maps to the same worker. All workers produce identical
results without coordination.

### ClusterNode

Per-worker coordinator. Routes spawns and lookups based on the hash ring.

```php
final class ClusterNode
{
    public function __construct(
        int $workerId,
        ActorSystem $system,
        Transport $transport,
        ConsistentHashRing $ring,
        ClusterSerializer $serializer,
        ActorDirectory $directory,
    );

    /** @return ActorRef<T> -- LocalActorRef if local, RemoteActorRef if remote */
    public function spawn(Props $props, string $name): ActorRef;

    /** @return ActorRef<object>|null */
    public function actorFor(string $path): ?ActorRef;

    public function start(): void;
    public function workerId(): int;
    public function system(): ActorSystem;
}
```

### RemoteActorRef

Implements `ActorRef<T>` for cross-worker messaging. Serializes the message
into an `Envelope`, then sends it via the `Transport`.

```php
/** @implements ActorRef<T> */
final readonly class RemoteActorRef implements ActorRef
{
    public function tell(object $message): void;
    public function ask(callable $messageFactory, Duration $timeout): object; // throws RuntimeException
    public function path(): ActorPath;
    public function isAlive(): bool;
}
```

`ask()` is not supported for remote actors and throws `RuntimeException`.

## Interfaces

### Transport

Inter-worker message transport abstraction.

```php
interface Transport
{
    public function send(int $targetWorker, string $data): void;
    public function listen(callable $onMessage): void;
    public function close(): void;
}
```

Implementations: `InMemoryTransport` (testing), `UnixSocketTransport`
(production, in `nexus-cluster-swoole`).

### ActorDirectory

Maps actor paths to the worker ID that owns them.

```php
interface ActorDirectory
{
    public function register(string $path, int $workerId): void;
    public function lookup(string $path): ?int;
    public function remove(string $path): void;
    public function has(string $path): bool;
}
```

Implementations: `InMemoryDirectory` (testing), `SwooleTableDirectory`
(production, in `nexus-cluster-swoole`).

### ClusterSerializer

Serializes `Envelope` instances for transport between workers.

```php
interface ClusterSerializer
{
    public function serialize(Envelope $envelope): string;
    public function deserialize(string $data): Envelope;
}
```

Default implementation: `CompactClusterSerializer` (compact binary format,
~6x smaller than PHP native serialization). A `PhpNativeClusterSerializer`
is also available.

## Test doubles

The package includes in-memory implementations for unit testing:

- **`InMemoryTransport`** -- Records sent messages. Use `receive(string)` to
  simulate incoming messages and `getSent()` / `getSentTo(int)` to inspect
  outgoing messages.
- **`InMemoryDirectory`** -- Simple array-backed directory for testing.

## Static analysis

The [nexus-psalm](./psalm.md) plugin includes a rule specifically for cluster
safety:

- **NonSerializableClusterMessage** -- Flags messages sent via
  `RemoteActorRef::tell()` that lack a `#[MessageType]` attribute. Cluster
  messages must be registered in `TypeRegistry` for cross-worker serialization.

```php
use Monadial\Nexus\Serialization\MessageType;

#[MessageType('order.created')]
final readonly class OrderCreated {} // OK — registered

final readonly class UnregisteredEvent {} // ERROR when sent via RemoteActorRef
```

The standard `NonReadonlyMessage` rule also applies to cluster messages --
all messages (local and remote) must be `readonly`.
