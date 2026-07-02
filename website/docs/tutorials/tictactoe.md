---
sidebar_position: 3
title: Tic-tac-toe
related:
  - tutorials/wallet-app
  - core-concepts/behaviors
  - http/websockets
  - persistence/event-sourcing
  - guides/single-writer-aggregates
---

# Tic-tac-toe: multiplayer WebSocket game

Two players open the same URL in different browsers and play a game of tic-tac-toe in real time. Behind the scenes each game is an **event-sourced** actor — every command produces events, and the game's state is a fold of the event log — projected into a Doctrine read model for the lobby. A per-game `WebSocketChannelActor` fans out state changes to every attached socket. This is the shape of any turn-based online game — replace the 3×3 board with anything you like.

Source: [`examples/nexus-tictactoe/`](https://github.com/nexus-actors/nexus/tree/main/examples/nexus-tictactoe).

## What you will build

- A REST **lobby** — `GET /api/games` lists open games, `POST /api/games` mints one.
- A persistent **WebSocket channel** — `/ws/games/{id}` — that the two players (and any spectators) attach to.
- An **event-sourced game actor** — `EventSourcedBehavior`, the single writer for each game id — split into pure **DECIDE** rules (`GameRules`) that turn a command into events, and an **EVOLVE** fold (`GameState`) that rebuilds state from the log.
- A **read model** — the `games` table (`GameSession`) — projected from the write side so the lobby can list live games and render a board with a plain indexed query.
- A **channel actor** — owns the connection→identity binding, decodes each client frame into a typed *intent*, stamps identity server-side, and routes each reply (broadcast vs. private) to the right sockets.
- A **React SPA** with a lobby, game board, and token-based reconnect. React and Babel load from a CDN (with Subresource Integrity) so there is no Node toolchain.

## Why actors here

Two players are racing to place moves on the same board. Without single-writer discipline, both moves could interleave through the same row and either violate a rule (two X's back to back) or lose one of the moves entirely. There are three families of fix:

- **Row-level pessimistic locking (`SELECT … FOR UPDATE`).** Simple, blocks a Postgres worker for the length of every move, doesn't compose with WebSocket fan-out (you still need somebody to broadcast).
- **Optimistic locking + retry.** Cheaper on Postgres, more code in every handler, still needs a separate broadcast layer.
- **Actor per game id.** The game actor is the only writer. Two concurrent HTTP or WebSocket requests targeting game `X` are serialised inside that actor's mailbox. Move validation, persistence, and broadcast all share the same commit boundary. The game actor's reply and the channel actor's broadcast are guaranteed to reflect the same committed state.

The third option is what this example demonstrates. `GameRefFactory::of($gameId)` spawns at most one live actor per game id per worker thread — the [single-writer principle](../guides/single-writer-aggregates.md). Because the actor is event-sourced, that single writer also gets an audit log for free: the sequence of `PlayerJoined` / `MoveMade` / `GameWon` events *is* the game.

## Architecture

```
Browser (React SPA)                  Nexus worker thread
─────────────────                   ────────────────────
GET /api/games          ──▶  ListGamesHandler   ──▶  EntityManagerPool ─┐  read
POST /api/games         ──▶  CreateGameHandler  ──▶  seed lobby row     │  model
WS /ws/games/{id}       ──▶  GameChannelActor   ──▶  GameActor (ES)     │  (`games`
                                     │              (GameRefFactory::of)│   table)
                                     │               DECIDE → events    │
                                     │               persist to log     │
                                     │               EVOLVE → state ────┘  project
                                     ▼                    │
                              broadcast snapshot     Event log (Doctrine)
                              to all attached        nexus_event_journal
                              sockets
```

`GameChannelActor` and `GameActor` sit inside the same `ActorSystem`; the channel actor's `$ctx->self()` is the reply target on every command it forwards.

## The rules: DECIDE

Event sourcing splits an aggregate in two. The **decide** half is a pure function — given the current state and a command, it returns the events to record or a rejection. It never touches a database, an actor, or a clock, so you can unit-test every rule in isolation:

```php title="src/Domain/GameRules.php"
public static function move(GameState $state, MakeMove $command): GameDecision
{
    $mark = $state->markFor($command->playerId);

    if ($mark === null) {
        // $playerId is the seat token — the message names the rule, not the secret.
        return GameDecision::reject('player is not seated in this game');
    }

    if ($state->status !== GameStatus::InProgress) {
        return GameDecision::reject("cannot move on a {$state->status->value} game");
    }

    if ($mark !== $state->nextTurn) {
        return GameDecision::reject("it is {$state->nextTurn?->value}'s turn");
    }

    if ($state->board[$command->cellIndex] !== null) {
        return GameDecision::reject("cell {$command->cellIndex} is already occupied");
    }

    $board = $state->board()->place($command->cellIndex, $mark);
    $events = [new MoveMade($mark, $command->cellIndex)];

    $winner = $board->winner();

    if ($winner !== null) {
        $events[] = new GameWon($winner);
    } elseif ($board->isFull()) {
        $events[] = new GameDrawn();
    }

    return GameDecision::accept(...$events);
}
```

A rejection carries a *token-free* message: the "seat" is an unguessable capability token, so `GameRules` is careful never to echo it into a message that will be logged or sent to a client.

## The state: EVOLVE

The **evolve** half is the fold — apply one event to the state and return the next state. This is what the persistence engine replays on (re)spawn to rebuild the game before the first command:

```php title="src/Domain/State/GameState.php"
public function apply(GameEvent $event): self
{
    return match (true) {
        $event instanceof PlayerJoined  => $this->seat($event),      // second join → InProgress, X to move
        $event instanceof MoveMade      => $this->place($event),     // place mark, flip turn
        $event instanceof GameWon       => $this->finishWon($event->winner),
        $event instanceof GameDrawn     => $this->finishDrawn(),
        $event instanceof GameForfeited => $this->finishForfeited($event->winner),
    };
}
```

`GameState` is `readonly`; every arm returns a new instance. Because DECIDE and EVOLVE are both pure, the whole rulebook is tested without booting an `ActorSystem` — see `GameRulesTest` and `GameStateTest`.

## The game actor

`GameActor` wires those two halves into an `EventSourcedBehavior`. DECIDE runs in the command handler and turns a `GameDecision` into an `Effect`; EVOLVE is the event handler. After events persist, the actor replies and projects the new state into the read model:

```php title="src/Actor/GameActor.php"
public static function behavior(string $gameId, EventStore $store, GameReadModel $readModel, LoggerInterface $log): Behavior
{
    return EventSourcedBehavior::create(
        PersistenceId::of('Game', $gameId),
        GameState::empty($gameId),
        static fn(GameState $s, ActorContext $ctx, object $cmd): Effect => self::dispatch($s, $cmd, $readModel, $log), // DECIDE
        static fn(GameState $s, object $event): GameState => self::applyEvent($s, $event),                             // EVOLVE
    )
        ->withEventStore($store)
        ->toBehavior();
}

// A move: persist the events, reply the new snapshot, project it into the read model.
private static function onMutation(GameState $state, GameDecision $decision, ActorRef $replyTo, int $fd, ...): Effect
{
    if ($decision->isRejected()) {
        // Targeted failure: only the fd that acted hears about it.
        return Effect::reply($replyTo, new GameRejected((string) $decision->rejection, $fd));
    }

    return Effect::persist(...$decision->events)
        ->thenRun(static fn(GameState $next) => $readModel->apply($next->toSnapshot()))     // project read model
        ->thenReply($replyTo, static fn(GameState $next) => $next->toSnapshot());           // broadcast source
}
```

The reply target and the originating fd are transport concerns, so they ride an actor-layer `GameEnvelope` — never the domain command. The domain commands (`JoinGame`, `MakeMove`, `Forfeit`, `GetSnapshot`) are pure data with no `ActorRef` and no fd. Events persist *before* the reply and the projection fire, so a snapshot is never sent for a move that wasn't recorded.

The journal itself is pluggable — `GameActor` only depends on the `EventStore` interface. The example wires a `PooledDoctrineEventStore` that appends events to the `nexus_event_journal` table, borrowing a pooled `EntityManager` per operation so no connection is pinned per game. Point it at a different `EventStore` (in-memory for tests, `DbalEventStore` for a raw-DBAL setup) and the actor does not change.

## The channel actor: server-owned identity

One actor per game id, holding two collaborators — a `GameRefFactory` (which locates the event-sourced game actor for an id) and a `ClientFrameCodec`. It owns the connection→identity binding, and this is where the security model lives.

**A client never asserts who it is on a gameplay frame.** A `move` frame is `{"type":"move","cell":4}` — no player id. The codec decodes frames into *intents* (`JoinIntent`, `MoveIntent`, …), never domain commands. The channel actor turns an intent into a command by stamping the mover from the authenticated connection:

```php title="src/Http/Ws/GameChannelActor.php"
public function onMessage(ActorContext $ctx, WebSocketContext $conn, WebSocketFrame $frame, mixed $state): BehaviorWithState
{
    $gameId = self::gameIdFrom($conn);   // ?string — validated ULID, never throws

    if ($gameId === null || strlen($frame->text) > self::MAX_FRAME_BYTES) {
        $conn->send($this->codec->encodeError('invalid message'));
        return BehaviorWithState::same();
    }

    $intent = $this->codec->decode($frame->text);   // ?ClientIntent — no player id inside

    if ($intent === null) {
        $conn->send($this->codec->encodeError('invalid message'));
        return BehaviorWithState::same();
    }

    $command = $this->toCommand($conn, $intent, $state);   // stamps identity from $this->seats[fd]

    if ($command !== null) {
        $this->games->of($gameId)->tell(
            new GameEnvelope($command, $ctx->self(), $conn->id()),
        );
    }

    return BehaviorWithState::same();
}
```

`gameIdFrom` returns `?string` rather than throwing — a hand-crafted frame with a bad `{id}` can never take down the actor's receive loop; the connection is simply refused.

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

`DoctrineKit` builds the persistence side: the pooled connections for the lobby, the `PooledDoctrineEventStore` (durable event journal), the `DoctrineGameReadModel` projection, and the `GameRefFactory` that ties them to the game actor. The channel factory closure is how the channel actor receives its collaborators at spawn time — the `GameRefFactory`, the `ClientFrameCodec`, and a logger:

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

- [Wallet app](./wallet-app.md) — another event-sourced aggregate, REST-only, with a per-owner directory actor.
- [Event sourcing](../persistence/event-sourcing.md) — the full `EventSourcedBehavior` API: `Effect`, event/snapshot stores, replay, and recovery.
- [WebSockets](../http/websockets.md) — the reference page for `WebSocketChannelActor` and the DSL that registers it.
- [Single-writer aggregates](../guides/single-writer-aggregates.md) — why per-id actors beat row locks and optimistic retry for state races.
