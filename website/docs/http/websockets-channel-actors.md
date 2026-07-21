---
title: WebSocket channel actors
related:
  - http/websockets
  - core-concepts/actors
  - tutorials/tictactoe
  - operations/swoole-deadlock-detector
---

# WebSocket channel actors

:::caution Experimental
WebSocket support is experimental and not yet production-hardened. In particular, the WebSocket upgrade currently bypasses the HTTP auth middleware pipeline — enforce authentication outside Nexus (e.g. at a reverse proxy) until this is fixed.
:::

A **channel actor** is a single stateful actor that owns every WebSocket connection matching a URL path. Two clients connecting to `/ws/games/{id}` for the same `id` end up attached to the same actor; any frame from any client can trigger the actor to broadcast to all of them.

Channel actors solve the "fan out one client message to N others" problem without threading a connection registry through your code. The framework handles connection lifecycle, name resolution, and cleanup; you write only the message handler.

## When to use one

Use a channel actor when:

- Multiple WebSocket connections need to share **state** (a game, a chat room, a document, a live dashboard).
- One incoming frame must be delivered to **more than one client** on that path.
- You want the **same actor instance** to survive across connections opened seconds apart.

Use a plain [`WebSocketHandler`](./handlers.md) instead when each connection is independent (echo servers, per-client streams, notification pipes).

## The DSL

```php title="src/Boot/App.php"
$app->channel(
    '/ws/games/{id}',
    GameChannelActor::class,
    key: 'id',
    factory: static fn(): GameChannelActor => new GameChannelActor(
        $gameFactory,
        $codec,
        $log,
    ),
);
```

- `path` — the route pattern; supports FastRoute params (`{id}`, `{room}`, …).
- `actorClass` — must extend `WebSocketChannelActor<S>`.
- `key` — the path-param whose value shards the actor. Two connections with the same `id` reach the same actor; different ids get different actors.
- `factory` — optional closure that constructs the actor. Use it when the actor's constructor has dependencies. Without one the framework `new $actorClass()`s with zero args.

The framework routes connections deterministically: `ChannelActorNameResolver::resolve($id)` hashes the key into a stable name, and `ChannelActorRegistry` spawns at most one actor per name per worker. On the next connection with the same id, the existing actor is reused. When the last connection closes and the actor returns `Behavior::stopped()`, the registry auto-prunes the reference so the next connection spawns a fresh instance.

## The actor shape

```php title="src/Http/Ws/GameChannelActor.php"
/**
 * @extends WebSocketChannelActor<?GameSnapshot>
 */
final class GameChannelActor extends WebSocketChannelActor
{
    public function __construct(
        private readonly EntityRefFactory $games,
        private readonly ClientFrameCodec $codec,
        private readonly LoggerInterface $log,
    ) {}

    #[Override]
    public function initialState(): ?GameSnapshot
    {
        return null;
    }

    #[Override]
    public function onOpened(ActorContext $ctx, WebSocketContext $conn, mixed $state): BehaviorWithState
    {
        $gameId = self::gameIdFrom($conn);
        $this->games->of($gameId)->tell(
            new GameEnvelope(new GetSnapshot(), $ctx->self()),
        );

        return BehaviorWithState::same();
    }

    #[Override]
    public function onMessage(ActorContext $ctx, WebSocketContext $conn, WebSocketFrame $frame, mixed $state): BehaviorWithState
    {
        $command = $this->codec->decode($frame->text);
        // dispatch, tell aggregate actor, etc.
        return BehaviorWithState::same();
    }

    #[Override]
    public function handleAppMessage(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        if ($message instanceof GameSnapshot) {
            $this->broadcast($this->codec->encode($message));

            return BehaviorWithState::next($message);
        }

        return BehaviorWithState::same();
    }

    #[Override]
    public function onClosed(ActorContext $ctx, WebSocketContext $conn, int $code, mixed $state): BehaviorWithState
    {
        if ($this->connections() === []) {
            return BehaviorWithState::stopped();
        }

        return BehaviorWithState::same();
    }
}
```

Four hooks:

- **`onOpened`** — new connection joins. Cache is passed as `$state`; send it to the newcomer or query an authoritative source.
- **`onMessage`** — a frame arrives. Decode it, drive your domain, `broadcast()` if the result should reach everyone.
- **`handleAppMessage`** — a message arrived that isn't a WebSocket lifecycle event. In practice this is a **reply from another actor** you commanded (e.g. `GameSnapshot` from a per-id aggregate actor). Broadcast state changes here.
- **`onClosed`** — a connection went away. Return `Behavior::stopped()` when the last connection closes so the registry prunes the actor.

The `broadcast()` helper iterates `$this->attached` (a map of `fd → WebSocketContext`) and calls `$conn->send()` on each. `connections()` returns them as a list.

## Path parameters flow to the actor

Every FastRoute path parameter matched at the connection's `Open` event is attached to the stored `ServerRequestInterface` before the actor sees it. Inside your channel actor:

```php
private static function gameIdFrom(WebSocketContext $conn): string
{
    $id = $conn->request()->getAttribute('id');
    // $id is the string that filled {id} in the route pattern
    ...
}
```

The dispatcher calls `$ctx = $ctx->withRequest($upgrade->withAttribute(...))` for each matched param before firing `ChannelConnectionOpened`. This is important — earlier versions of Nexus threw away the params and channel actors had no way to know their own key without rehashing the actor name.

## Constructor injection

The factory closure runs once per **actor spawn** (i.e. once per unique key per worker), not per connection. So dependencies live for the lifetime of the actor:

```php
$app->channel(
    '/ws/games/{id}',
    GameChannelActor::class,
    key: 'id',
    factory: static fn(): GameChannelActor => new GameChannelActor(
        $gameFactory,   // shared per-worker
        $codec,         // shared per-worker
        $log,           // shared per-worker
    ),
);
```

Everything the actor needs — an `EntityRefFactory` for aggregate access, a `MessageSerializer` for wire encoding, a `LoggerInterface` for structured logs — should be constructed once in your composition root and passed here. Do not use `#[FromActor]` / `#[FromService]` inside a channel actor — those attributes are for `WebSocketHandler` subclasses (single-connection mode) and do not apply here.

## Logging inside a channel actor

Prefer passing a `LoggerInterface` explicitly and calling `$this->log->info(...)`. The alternative — `$ctx->log()` on `ActorContext` — resolves to whatever logger was configured for the `ActorSystem` at boot, which defaults to `NullLogger`. In `SwooleWorkerServer` mode the framework wires the server config's logger through to the ActorSystem, so `$ctx->log()` works when you call `SwooleWorkerConfig::bind(...)->logger($psrLogger)` at boot.

## What the framework hooks look like from inside

The base `WebSocketChannelActor::handle()` translates raw Swoole events into the four override points:

| Framework message | Translated to | State visible? |
|---|---|---|
| `ChannelConnectionOpened(fd, ctx, upgrade)` | `onOpened(ctx, $conn, $state)` | yes (`?S`) |
| `ChannelMessageReceived(fd, frame)` | `onMessage(ctx, $conn, $frame, $state)` | yes |
| `ChannelConnectionClosed(fd, code)` | `onClosed(ctx, $conn, $code, $state)` | yes |
| Any other object (typically an actor reply) | `handleAppMessage(ctx, $msg, $state)` | yes |

`$this->attached[$fd]` is maintained automatically:

- Set on `ChannelConnectionOpened` **before** `onOpened` fires.
- Cleared on `ChannelConnectionClosed` **before** `onClosed` fires.

So `broadcast()` and `connections()` always reflect the current set — you never need to touch them yourself.

## Common patterns

**Fan-out to spectators.** Aggregate actor replies with a snapshot; channel actor stores it in state and broadcasts on every reply:

```php
public function handleAppMessage(...): BehaviorWithState
{
    if (!$message instanceof GameSnapshot) return BehaviorWithState::same();

    $this->broadcast($this->codec->encode($message));

    return BehaviorWithState::next($message);
}
```

**Late-join catch-up.** Send cached state to the new connection *before* querying the aggregate:

```php
public function onOpened(...): BehaviorWithState
{
    if ($state !== null) {
        $conn->send($this->codec->encode($state));
    }

    // still refresh in case the cache is stale
    $this->games->of($gameId)->tell(new GameEnvelope(new GetSnapshot(), $ctx->self()));

    return BehaviorWithState::same();
}
```

**Reply to sender only.** Avoid amplifying a client's read poll into a broadcast. When `onMessage` sees a read-only command AND the cache is warm, reply directly:

```php
if ($command instanceof GetSnapshot && $state !== null) {
    $conn->send($this->codec->encode($state));
    return BehaviorWithState::same();
}
```

**Identity binding to prevent spoofing.** Trust the client's `playerId` on join, then stamp it from a per-fd map on subsequent frames:

```php
/** @var array<int, string> */
private array $identities = [];

private function authorize(WebSocketContext $conn, GameCommand $cmd): ?GameCommand
{
    $fd = $conn->id();

    if ($cmd instanceof JoinGame) {
        $this->identities[$fd] = $cmd->playerId;
        return $cmd;
    }

    return $cmd->playerId === ($this->identities[$fd] ?? null) ? $cmd : null;
}
```

**Self-passivate on empty.** Return `Behavior::stopped()` when the attached set drains so the registry prunes the ref:

```php
public function onClosed(...): BehaviorWithState
{
    if ($this->connections() === []) {
        return BehaviorWithState::stopped();
    }

    return BehaviorWithState::same();
}
```

## Deployment mode compatibility

Channel actors **require process mode** (`SwooleWorkerServer`). The framework detects the mode at boot:

- **`SwooleThreadServer` — rejects channel routes** via `WebSocketRouter::assertNoChannelRoutes()`. In thread mode each worker thread has its own address space; a channel actor spawned on worker A can't reach connections attached to worker B. Rather than degrade silently, the boot fails.
- **`SwooleWorkerServer` — supported.** All workers share the process's memory. With `->workers(1)` the channel actor sees every connection. With `->workers(n > 1)` connections are dispatched to workers by `dispatch_mode`; you'd need a pub/sub layer (Postgres LISTEN/NOTIFY, Redis) to fan out cross-worker.

For multiplayer games and other stateful WebSocket workloads, use `SwooleWorkerServer::run(SwooleWorkerConfig::bind(...)->workers(1)->enableWebSocket(), ...)`. See the [tic-tac-toe tutorial](../tutorials/tictactoe.md) for a complete example.

## Bounding the channel population (security)

Because a channel actor is spawned per distinct URL key, an attacker who can reach a public, non-passivating channel route can churn unique keys to spawn actors, refs, and mailboxes without limit. Nexus bounds this by default:

- **Cardinality cap.** The registry caps the number of simultaneously live channel actors (default `ChannelActorRegistry::DEFAULT_MAX_CHANNELS` = 10 000). Past the cap, a new channel connection is refused with a `1013` close instead of spawning. Tune it with `WsApplication::withMaxChannels()`. Dead channels free their slot.
- **Bounded mailboxes.** Channel actors get a bounded mailbox (1 024, `DropNewest`) so one flooding connection cannot grow a channel's queue without limit.
- **Passivate on last close.** Return `$this->stopWhenEmpty($state)` from `onClosed()` so a channel actor stops once its final connection closes, freeing its cap slot:

```php
public function onClosed(ActorContext $ctx, WebSocketContext $conn, int $code, mixed $state): BehaviorWithState
{
    return $this->stopWhenEmpty($state);
}
```

Combine these with authentication on the upgrade (see [Auth — WebSocket auth](./auth.md#websocket-auth)) and an [Origin allow-list](./auth.md#cross-site-protection-origin-allow-list) so only authorized, same-site clients can open channels at all.

## Operational notes

- **The "worker exit timeout" cycle.** Under `SwooleWorkerServer`, idle workers can be force-terminated by Swoole's watchdog. Actors survive respawn as long as their authoritative state is persisted (Doctrine row, event store). See [Swoole deadlock-detector false positives](../operations/swoole-deadlock-detector.md) for the full explanation.
- **Client auto-reconnect.** WebSocket clients should back off and reconnect on close code `1006`/`1011`. Any React/JS client should handle this — see the tic-tac-toe SPA for a simple example.
- **Cross-worker fan-out.** Single-worker is fine for tens of thousands of connections thanks to Swoole coroutines. If you need to scale beyond one process, put a pub/sub bus between workers and forward broadcasts through it; the channel actor pattern above still works, you just wire the broadcast to also publish upstream.

## See also

- [Handlers](./handlers.md) — the single-connection alternative.
- [WebSockets overview](./websockets.md) — the DSL, framing, and connection lifecycle.
- [Tic-tac-toe tutorial](../tutorials/tictactoe.md) — an end-to-end channel-actor example.
- [Swoole deadlock-detector false positive](../operations/swoole-deadlock-detector.md) — the idle-worker cycling behavior and mitigations.
