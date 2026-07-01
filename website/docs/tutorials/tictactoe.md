---
sidebar_position: 3
title: Tic-tac-toe
related:
  - tutorials/wallet-app
  - core-concepts/behaviors
  - http/websockets
  - doctrine/entity-behavior
  - best-practices/single-writer-aggregates
---

# Tic-tac-toe: multiplayer WebSocket game

Two players open the same URL in different browsers and play a game of tic-tac-toe in real time. Behind the scenes each game is a persisted Doctrine aggregate driven by a per-id `EntityBehavior` actor; a per-game `WebSocketChannelActor` fans out state changes to every attached socket. This is the shape of any turn-based online game — replace the 3×3 board with anything you like.

Source: [`examples/nexus-tictactoe/`](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-tictactoe).

## What you will build

- A REST **lobby** — `GET /api/games` lists open games, `POST /api/games` mints one.
- A persistent **WebSocket channel** — `/ws/games/{id}` — that the two players (and any spectators) attach to.
- A **game aggregate** — `GameSession` — that holds every rule: whose turn it is, whether a cell is occupied, when the game ends.
- A **game actor** driven by `EntityBehavior` — the single writer for each game id, flushing to Postgres on every move.
- A **channel actor** — decodes each client JSON frame into a typed command, forwards to the game actor, and broadcasts the reply snapshot to every attached socket.
- A **React SPA** with a lobby, game board, and reconnect via URL hash. React and Babel load from a CDN so there is no Node toolchain.

## Why actors here

Two players are racing to place moves on the same board. Without single-writer discipline, both moves could interleave through the same row and either violate a rule (two X's back to back) or lose one of the moves entirely. There are three families of fix:

- **Row-level pessimistic locking (`SELECT … FOR UPDATE`).** Simple, blocks a Postgres worker for the length of every move, doesn't compose with WebSocket fan-out (you still need somebody to broadcast).
- **Optimistic locking + retry.** Cheaper on Postgres, more code in every handler, still needs a separate broadcast layer.
- **Actor per game id.** The game actor is the only writer. Two concurrent HTTP or WebSocket requests targeting game `X` are serialised inside that actor's mailbox. Move validation, persistence, and broadcast all share the same commit boundary. The game actor's reply and the channel actor's broadcast are guaranteed to reflect the same committed state.

The third option is what this example demonstrates. `EntityRefFactory::of($gameId)` spawns at most one live actor per game id per worker thread — the [single-writer principle](../best-practices/single-writer-aggregates.md).

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

Every rule check throws a `GameDomainException` subclass. The actor doesn't validate; it just calls the aggregate and lets the exception propagate. The channel actor catches those exceptions and sends an `{"type":"error"}` frame back to the offending client without touching the rest of the game.

## The game actor

The actor is a thin command dispatcher. The Doctrine wiring lives in `DoctrineKit` — this class exposes only the pure handler:

```php title="src/Actor/GameActor.php"
public static function handler(): Closure
{
    return static function (ActorContext $ctx, GameEnvelope $env, GameSession $game): EntityEffect {
        $cmd = $env->command;
        $replyTo = $env->replyTo;

        return match (true) {
            $cmd instanceof JoinGame    => self::mutateAndReply($replyTo, static fn() => $game->join($cmd->playerId, $cmd->playerName)),
            $cmd instanceof MakeMove    => self::mutateAndReply($replyTo, static fn() => $game->makeMove($cmd->playerId, $cmd->cellIndex)),
            $cmd instanceof Forfeit     => self::mutateAndReply($replyTo, static fn() => $game->forfeit($cmd->playerId)),
            $cmd instanceof GetSnapshot => self::snapshot($game, $replyTo),
        };
    };
}
```

The handler is an exhaustive `match(true)` over `GameCommand`. Psalm proves at type-check time that every implementation is handled — add a new command class and the compiler tells you where.

Note the split: the domain commands (`JoinGame`, `MakeMove`, `Forfeit`, `GetSnapshot`) are pure data with no `ActorRef` field. The reply target is transport, so it rides an actor-layer `GameEnvelope` that pairs the command with the sender's `ActorRef<GameSnapshot>`. This keeps the domain framework-free — you can unit-test the aggregate and command classes without ever booting an `ActorSystem`.

## The channel actor

One actor per game id. Two collaborators — an `EntityRefFactory` and a `ClientFrameCodec` — nothing else. All JSON encoding/decoding goes through the injected `MessageSerializer` inside the codec; there is no `json_decode` in the actor itself.

```php title="src/Http/Ws/GameChannelActor.php"
public function onMessage(
    ActorContext $ctx,
    WebSocketContext $conn,
    WebSocketFrame $frame,
    mixed $state,
): BehaviorWithState {
    $command = $this->codec->decode($frame->data);

    if ($command === null) {
        $conn->send($this->codec->encodeError('invalid message'));
        return BehaviorWithState::same();
    }

    $this->games->of(self::gameIdFrom($conn))->tell(
        new GameEnvelope($command, $ctx->self()),   // wrap once at the boundary
    );

    return BehaviorWithState::same();
}

public function handleAppMessage(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
{
    if (!$message instanceof GameSnapshot) {
        return BehaviorWithState::same();
    }

    $this->broadcast($this->codec->encode($message));   // serialized via ValinorJsonSerializer
    return BehaviorWithState::next($message);           // cache for late joiners
}
```

The channel actor wraps the pure domain command in a `GameEnvelope` right at the boundary — `$ctx->self()` is the reply target. The game actor unwraps, dispatches, and replies to the wrapped `ActorRef`. Two players see the same authoritative snapshot from the same commit — because the actor is the sole writer.

## Wiring it up

The composition root is `src/Boot/TicTacToeApp.php`. Every worker thread runs `factory($config)`:

```php title="src/Boot/TicTacToeApp.php"
$app = WsApplication::create($system);
$app->withMessageSerializer(new ValinorJsonSerializer());

// Doctrine pools + per-request scope
$app->middleware(new ConnectionScopeMiddleware($doctrine->connPool));
$app->middleware(new EntityManagerScopeMiddleware($doctrine->emPool));
$app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));
$app->paramResolver(new EntityManagerResolver());

TicTacToeRoutes::register($app, $doctrine->gameFactory);
```

Routes are declared in one place. Note the channel factory closure — this is how the channel actor receives its `EntityRefFactory` at spawn time:

```php title="src/Http/TicTacToeRoutes.php"
$app->channel(
    '/ws/games/{id}',
    GameChannelActor::class,
    key: 'id',
    factory: static fn(): GameChannelActor => new GameChannelActor($gameFactory),
);
```

The `key: 'id'` parameter tells the framework which URL segment identifies the channel; `ChannelActorNameResolver::resolve($id)` maps it to a stable actor name so every connection for the same game finds the same actor.

## Wire protocol

Client to server (JSON text frames):

```json
{"type": "join",     "playerId": "p_abc", "playerName": "Alice"}
{"type": "move",     "playerId": "p_abc", "cellIndex": 4}
{"type": "forfeit",  "playerId": "p_abc"}
{"type": "snapshot"}
```

Server to client:

```json
{"type": "snapshot", "gameId": "01JX...", "status": "in_progress",
 "playerX": {"id": "p_abc", "name": "Alice"},
 "playerO": {"id": "p_def", "name": "Bob"},
 "board":   [null, null, "X", null, "O", null, null, null, null],
 "nextTurn": "O", "winner": null}
{"type": "error", "message": "it is X's turn"}
```

Board cells are row-major (indices 0..8).

## Run it

```bash
cd examples/nexus-tictactoe
make build          # PHP 8.5 ZTS + Swoole 6.0 thread-mode
make install        # composer install inside the container
make up             # start the server on :9080
make logs           # tail the workers
```

Then open [http://localhost:9080/](http://localhost:9080/) in two browser tabs. The first tab creates a game; the second joins from the lobby. Each tab has its own `playerId` in `localStorage`.

## Where to go next

- [Wallet app](./wallet-app.md) — the same `EntityBehavior` pattern with event-sourced writes and REST-only I/O.
- [WebSockets](../http/websockets.md) — the reference page for `WebSocketChannelActor` and the DSL that registers it.
- [Single-writer aggregates](../best-practices/single-writer-aggregates.md) — why per-id actors beat row locks and optimistic retry for state races.
- [Doctrine / EntityBehavior DSL](../doctrine/entity-behavior.md) — full API for `EntityRefFactory`, `EntityEffect`, and passivation.
