---
sidebar_position: 3
title: Tic-tac-toe
related:
  - tutorials/wallet-app
  - core-concepts/behaviors
  - http/websockets
  - doctrine/entity-behavior
  - guides/single-writer-aggregates
---

# Tic-tac-toe: multiplayer WebSocket game

Two players open the same URL in different browsers and play a game of tic-tac-toe in real time. Behind the scenes each game is a persisted Doctrine aggregate driven by a per-id `EntityBehavior` actor; a per-game `WebSocketChannelActor` fans out state changes to every attached socket. This is the shape of any turn-based online game — replace the 3×3 board with anything you like.

Source: [`examples/nexus-tictactoe/`](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-tictactoe).

## What you will build

- A REST **lobby** — `GET /api/games` lists open games, `POST /api/games` mints one.
- A persistent **WebSocket channel** — `/ws/games/{id}` — that the two players (and any spectators) attach to.
- A **game aggregate** — `GameSession` — that holds every rule: whose turn it is, whether a cell is occupied, when the game ends.
- A **game actor** driven by `EntityBehavior` — the single writer for each game id, flushing to Postgres on every move.
- A **channel actor** — owns the connection→identity binding, decodes each client frame into a typed *intent*, stamps identity server-side, and routes each reply (broadcast vs. private) to the right sockets.
- A **React SPA** with a lobby, game board, and token-based reconnect. React and Babel load from a CDN (with Subresource Integrity) so there is no Node toolchain.

## Why actors here

Two players are racing to place moves on the same board. Without single-writer discipline, both moves could interleave through the same row and either violate a rule (two X's back to back) or lose one of the moves entirely. There are three families of fix:

- **Row-level pessimistic locking (`SELECT … FOR UPDATE`).** Simple, blocks a Postgres worker for the length of every move, doesn't compose with WebSocket fan-out (you still need somebody to broadcast).
- **Optimistic locking + retry.** Cheaper on Postgres, more code in every handler, still needs a separate broadcast layer.
- **Actor per game id.** The game actor is the only writer. Two concurrent HTTP or WebSocket requests targeting game `X` are serialised inside that actor's mailbox. Move validation, persistence, and broadcast all share the same commit boundary. The game actor's reply and the channel actor's broadcast are guaranteed to reflect the same committed state.

The third option is what this example demonstrates. `EntityRefFactory::of($gameId)` spawns at most one live actor per game id per worker thread — the [single-writer principle](../guides/single-writer-aggregates.md).

## Architecture

```
Browser (React SPA)                  Nexus worker thread
─────────────────                   ────────────────────
GET /api/games          ──▶  ListGamesHandler   ──▶  EntityManagerPool
POST /api/games         ──▶  CreateGameHandler  ──▶  persist empty row
WS /ws/games/{id}       ──▶  GameChannelActor   ──▶  GameActor
                                     │                    │  (EntityRefFactory::of)
                                     │                    ▼
                                     │             GameSession (Doctrine entity)
                                     ▼                    │
                              broadcast snapshot     flush on every move
                              to all attached             │
                              sockets                Postgres `games` row
```

`GameChannelActor` and `GameActor` sit inside the same `ActorSystem`; the channel actor's `$ctx->self()` is the reply target on every command it forwards.

## The aggregate

`GameSession` (the Doctrine entity under `Domain/Entity/`) enforces every rule:

```php title="src/Domain/Entity/GameSession.php"
public function makeMove(string $playerId, int $cellIndex): void
{
    if ($this->status !== GameStatus::InProgress) {
        throw new GameOverException("cannot move on a {$this->status->value} game");
    }

    $mark = $this->markFor($playerId);

    if ($mark !== $this->nextTurn) {
        throw new NotYourTurnException("it is {$this->nextTurn?->value}'s turn");
    }

    $board = $this->board()->place($cellIndex, $mark);
    $this->board = $board->toArray();

    // …winner / draw check, then advance $this->nextTurn
}
```

Every rule check throws a `GameDomainException` subclass. The actor doesn't validate; it just calls the aggregate and lets the exception surface. The game actor catches those exceptions and replies with a `GameRejected` message carrying the offending connection's fd, so the error reaches only that one client — not every spectator.

## The game actor

The actor is a thin command dispatcher. The Doctrine wiring lives in `DoctrineKit` — this class exposes only the pure handler. It replies with one of three actor-layer messages, each routed differently by the channel actor:

```php title="src/Actor/GameActor.php"
public static function handler(LoggerInterface $log): Closure
{
    return static function (ActorContext $ctx, GameEnvelope $env, GameSession $game) use ($log): EntityEffect {
        $cmd = $env->command;
        $replyTo = $env->replyTo;
        $fd = $env->originFd;

        try {
            return match (true) {
                // A join broadcasts the new state AND privately welcomes the joiner.
                $cmd instanceof JoinGame => self::persist(
                    $replyTo,
                    static fn() => $game->join($cmd->playerId, $cmd->playerName),
                    static fn(GameSession $g): Seated => new Seated($g->toSnapshot(), $fd, $cmd->playerId),
                ),
                $cmd instanceof MakeMove => self::persist(
                    $replyTo,
                    static fn() => $game->makeMove($cmd->playerId, $cmd->cellIndex),
                    static fn(GameSession $g) => $g->toSnapshot(),
                ),
                $cmd instanceof Forfeit => self::persist(
                    $replyTo,
                    static fn() => $game->forfeit($cmd->playerId),
                    static fn(GameSession $g) => $g->toSnapshot(),
                ),
                $cmd instanceof GetSnapshot => self::read($game, $replyTo),
                default => throw new LogicException('unhandled GameCommand: ' . $cmd::class),
            };
        } catch (GameDomainException $e) {
            // Targeted failure: only the fd that acted hears about it.
            $replyTo->tell(new GameRejected($e->getMessage(), $fd));

            return EntityEffect::same();
        }
    };
}
```

The reply target and the originating fd are transport concerns, so they ride an actor-layer `GameEnvelope` — never the domain command. The domain commands (`JoinGame`, `MakeMove`, `Forfeit`, `GetSnapshot`) are pure data with no `ActorRef` and no fd, so you can unit-test the aggregate without ever booting an `ActorSystem`.

## The channel actor: server-owned identity

One actor per game id, holding two collaborators — an `EntityRefFactory` and a `ClientFrameCodec`. It owns the connection→identity binding, and this is where the security model lives.

**A client never asserts who it is on a gameplay frame.** A `move` frame is `{"type":"move","cell":4}` — no player id. The codec decodes frames into *intents* (`JoinIntent`, `MoveIntent`, …), never domain commands. The channel actor turns an intent into a command by stamping the mover from the authenticated connection:

```php title="src/Http/Ws/GameChannelActor.php"
public function onMessage(ActorContext $ctx, WebSocketContext $conn, WebSocketFrame $frame, mixed $state): BehaviorWithState
{
    if (strlen($frame->text) > self::MAX_FRAME_BYTES) {
        $conn->send($this->codec->encodeError('frame too large'));
        return BehaviorWithState::same();
    }

    $intent = $this->codec->decode($frame->text);   // ?ClientIntent — no player id inside

    if ($intent === null) {
        $conn->send($this->codec->encodeError('invalid message'));
        return BehaviorWithState::same();
    }

    $command = $this->toCommand($conn, $intent, $state);   // stamps identity from $this->seats[fd]

    if ($command !== null) {
        $this->games->of(self::gameIdFrom($conn))->tell(
            new GameEnvelope($command, $ctx->self(), $conn->id()),
        );
    }

    return BehaviorWithState::same();
}
```

Identity is issued by the server, not chosen by the client:

- On the **first join** the server mints an unguessable capability token (a ULID). On a **reconnect** the client presents the token it stored last time.
- After the aggregate seats the player, the game actor replies `Seated`. The channel actor binds `fd → token`, **broadcasts** the new state to everyone, and sends *only the joining connection* a private `welcome` with its token and mark:

```php title="src/Http/Ws/GameChannelActor.php"
public function handleAppMessage(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
{
    if ($message instanceof Seated) {
        $this->seats[$message->fd] = $message->token;
        $mark = self::markOf($message->snapshot, $message->token);
        $this->connection($message->fd)?->send($this->codec->encodeWelcome($mark, $message->token)); // private
        $this->broadcast($this->codec->encodeSnapshot($message->snapshot));                            // everyone
        return BehaviorWithState::next($message->snapshot);
    }

    if ($message instanceof GameRejected) {
        $this->connection($message->fd)?->send($this->codec->encodeError($message->reason));           // one fd
        return BehaviorWithState::same();
    }

    if ($message instanceof GameSnapshot) {
        $this->broadcast($this->codec->encodeSnapshot($message));                                       // everyone
        return BehaviorWithState::next($message);
    }

    return BehaviorWithState::same();
}
```

The token is **never broadcast** — the broadcast snapshot carries name-only seats (`SnapshotPayload`), so a spectator can't read another player's token off the wire. That's what closes the door on impersonation: the id used to move is bound to the connection at join time and can't be learned or forged by anyone else. `connection($fd)` (a `WebSocketChannelActor` helper) sends to exactly one attached socket, so a rejected move or a welcome reaches only the client it's for.

## Wiring it up

The composition root is `src/Boot/App.php`. It runs on `SwooleWorkerServer` (process mode) — channel actors need shared memory to fan out to every connection, and the thread server rejects channel routes at boot for exactly that reason. Every worker runs `factory($config)`:

```php title="src/Boot/App.php"
$app = WsApplication::create($system);
$app->withMessageSerializer($serializer);

// Doctrine pools + per-request scope
$app->middleware(new ConnectionScopeMiddleware($doctrine->connPool));
$app->middleware(new EntityManagerScopeMiddleware($doctrine->emPool));
$app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));
$app->paramResolver(new EntityManagerResolver());

Routes::register($app, $doctrine->gameFactory, $serializer, $indexHandler, $log);
```

The channel factory closure is how the actor receives its collaborators at spawn time — an `EntityRefFactory`, the `ClientFrameCodec`, and a logger:

```php title="src/Http/Routes.php"
$codec = new ClientFrameCodec($serializer);

$app->channel(
    '/ws/games/{id}',
    GameChannelActor::class,
    key: 'id',
    factory: static fn(): GameChannelActor => new GameChannelActor($gameFactory, $codec, $log),
);
```

The `key: 'id'` parameter tells the framework which URL segment shards the channel; `ChannelActorNameResolver::resolve($id)` maps it to a stable actor name so every connection for the same game finds the same actor.

## Wire protocol

Client to server — gameplay frames carry no identity; the server knows who you are from the connection:

```json
{"type": "join",     "name": "Alice"}
{"type": "join",     "name": "Alice", "token": "01JX..."}   // reconnect
{"type": "move",     "cell": 4}
{"type": "forfeit"}
{"type": "snapshot"}
```

Server to client — three frame kinds. `snapshot` is broadcast to everyone (name-only seats, no tokens); `welcome` and `error` go privately to one connection:

```json
{"type": "snapshot", "data": {
    "gameId": "01JX...", "status": "in_progress",
    "playerX": {"name": "Alice"}, "playerO": {"name": "Bob"},
    "board": [null, null, "X", null, "O", null, null, null, null],
    "nextTurn": "O", "winner": null
}}
{"type": "welcome", "data": {"mark": "X", "token": "01JX..."}}
{"type": "error",   "data": {"message": "it is X's turn"}}
```

Board cells are row-major (indices 0..8). A malformed or oversized frame, or a `/ws/games/{id}` whose `{id}` is not a ULID, is rejected — see the [security notes](#security-notes) below.

## Run it

```bash
cd examples/nexus-tictactoe
make build          # PHP 8.5 ZTS + Swoole 6.2 (zlib-enabled) worker mode
make install        # composer install inside the container
make up             # start the server on :9080
make logs           # tail the workers
```

Then open [http://localhost:9080/](http://localhost:9080/) in two browser tabs. The first tab creates a game; the second joins from the lobby. Each tab stores its seat **token** (issued by the server) in `localStorage`, keyed by game id, so a refresh reclaims the same seat.

## Security notes

The example is deliberately hardened so it can be read as a template, not just a demo:

- **Identity is server-owned.** The client never sends a player id on `move`/`forfeit`; the server stamps it from the connection. Reconnect uses an unguessable token issued privately at join and never broadcast — so no client can learn, let alone forge, another player's identity.
- **Replies are targeted.** A rejected move or a welcome reaches only the connection it concerns, via `WebSocketChannelActor::connection($fd)`. Broadcasts are reserved for committed state everyone should see.
- **Input is bounded.** Frames over 4 KB are rejected before parsing; names are length-capped and stripped of control characters; `{id}` must be a ULID or the upgrade is closed. HTTP error responses never echo internal exception text.
- **The SPA pins its CDN scripts with Subresource Integrity** so a compromised CDN can't inject code.

What the example intentionally leaves to you: authentication/rate-limiting on `POST /api/games`, a lobby reaper for finished games, and cross-worker fan-out (single-worker is plenty for a game; scaling out needs a pub/sub bus — see [Scaling](../scaling/overview.md)).

## Where to go next

- [Wallet app](./wallet-app.md) — the same `EntityBehavior` pattern with event-sourced writes and REST-only I/O.
- [WebSockets](../http/websockets.md) — the reference page for `WebSocketChannelActor` and the DSL that registers it.
- [Single-writer aggregates](../guides/single-writer-aggregates.md) — why per-id actors beat row locks and optimistic retry for state races.
- [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md) — full API for `EntityRefFactory`, `EntityEffect`, and passivation.
