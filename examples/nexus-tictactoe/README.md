# nexus-tictactoe

Multiplayer tic-tac-toe over WebSocket. A single-file React SPA talks to a
Nexus Swoole-Threads HTTP server; every game is an **event-sourced** actor —
commands produce events, the state is a fold of the log — projected into a
Doctrine read model for the lobby. Broadcast between the two players (and any
spectators) goes through a `WebSocketChannelActor` keyed by game id.

## What it shows

- **`EventSourcedBehavior` (CQRS)** — the game actor is split into DECIDE
  (pure `GameRules` turns a command into events or a token-free rejection)
  and EVOLVE (`GameState` folds each event). The event log is the source of
  truth; on (re)spawn the persistence engine replays it to rebuild state.
  Single writer per game id.
- **Read model as a projection** — after each persist the actor projects the
  new state into the `games` table (`GameSession`), so the REST lobby can
  answer "which games are live?" and "show me the board" with a plain
  indexed query — the one thing an event log alone can't do cheaply.
- **`WebSocketChannelActor` for fan-out** — one channel actor per game id
  attaches every connected client; snapshot replies from the game actor
  broadcast to all attached sockets.
- **Actor + HTTP composition** — a REST lobby (`GET /api/games`,
  `POST /api/games`) and a persistent WebSocket (`/ws/games/{id}`) share
  the same `WsApplication`, Doctrine pool, and worker threads.
- **Reconnection & spectator support** — a joining client receives the
  cached snapshot immediately; the game id lives in the URL hash so
  refreshing a tab picks the game right back up.
- **Worker-pool sharding** — game actors are addressed by
  `GameRefFactory::of($id)`; the read model is shared, so any worker thread
  can serve any game.

## Run it

```bash
make build           # PHP 8.5 ZTS + Swoole 6.2 (zlib-enabled) worker mode
make install         # composer install inside the container
make up              # start the server on http://localhost:9080
make logs            # tail worker logs
```

Then open [http://localhost:9080/](http://localhost:9080/) in **two browser
tabs** (or an incognito window, or share the URL with a friend). The first
tab creates a game; the second joins from the lobby. Each tab stores its
server-issued seat **token** in `localStorage` (keyed by game id), so both
count as distinct players and a refresh reclaims the same seat.

## Architecture

```
Browser (React SPA)                Nexus worker thread
  ─────────────────                 ─────────────────────────
  GET  /api/games       ─▶ HTTP handler ─▶ Doctrine EM (pool)  ─┐  read
  POST /api/games       ─▶ CreateGameHandler ─▶ seed row        │  model
  WS   /ws/games/{id}   ─▶ GameChannelActor  ─▶ GameActor       │  (`games`
                                      │            │ (ES)       │   table)
                                      │       DECIDE → events   │
                                      │       persist to log    │
                                      │       EVOLVE → state ───┘  project
                                      ▼            │
                                  broadcast    Event log
                                  snapshot     (InMemory → Dbal)
                                  to all
                                  attached sockets
```

- The **game actor** is spawned lazily by `GameRefFactory::of($gameId)`. It
  handles the sealed `GameCommand` set (`JoinGame`, `MakeMove`, `Forfeit`,
  `GetSnapshot`) wrapped in a `GameEnvelope`, asks `GameRules` for the
  events, persists them, and only then replies + projects the new state into
  the `games` read model.
- The **channel actor** decodes each client JSON frame into a typed intent,
  stamps identity from the connection, forwards a command to the game actor
  with itself as the reply target, then caches and broadcasts each
  `GameSnapshot` that comes back.
- The journal is a per-worker `InMemoryEventStore` for the demo. Swap it for
  `DbalEventStore` (nexus-persistence-dbal) to persist events durably — the
  actor code does not change.

## Wire protocol

Client → server — gameplay frames carry **no identity**; the server knows
who you are from the connection. Each frame is decoded into a typed
*intent*, never a domain command with a client-supplied player id:

```json
{"type": "join",     "name": "Alice"}
{"type": "join",     "name": "Alice", "token": "01JX..."}   // reconnect
{"type": "move",     "cell": 4}
{"type": "forfeit"}
{"type": "snapshot"}
```

Server → client — every frame is `{type, data}`. `snapshot` is broadcast
to everyone with **name-only** seats (tokens are never on the wire);
`welcome` and `error` are sent privately to a single connection:

```json
{"type": "snapshot", "data": {
    "gameId":   "01JX...",
    "status":   "in_progress",
    "playerX":  {"name": "Alice"},
    "playerO":  {"name": "Bob"},
    "board":    [null, null, "X", null, "O", null, null, null, null],
    "nextTurn": "O",
    "winner":   null
}}
{"type": "welcome", "data": {"mark": "X", "token": "01JX..."}}
{"type": "error",   "data": {"message": "it is X's turn"}}
```

Board cells are row-major (indices 0..8).

**Session identity.** Identity is **server-owned**. On the first join the
server mints an unguessable capability token, seats the player under it,
binds it to the connection, and hands *only that connection* the token in
a private `welcome`. Every later `move`/`forfeit` is stamped from the
connection binding — the client never sends a player id, so it can neither
spoof nor learn another player's identity (tokens are never broadcast).
Reconnect presents the stored token to reclaim the same seat.

## Package layout

```
src/
  Boot/           # composition root
    App.php            # per-worker HTTP+WS factory
    Bootstrap.php      # main-thread once-only wiring (Swoole coroutine hook)
    Config.php / DbConfig.php / HttpConfig.php
    DoctrineKit.php    # pools + event store + GameRefFactory (persistence wiring)
    SchemaBootstrap.php
  Domain/         # framework-agnostic — no imports from Actor/ or Http/
    Command/      # marker + JoinGame / MakeMove / Forfeit / GetSnapshot
    Event/        # PlayerJoined / MoveMade / GameWon / GameDrawn / GameForfeited
    State/        # GameState — immutable fold of the event log
    GameRules.php # pure DECIDE: (state, command) → events or rejection
    GameDecision.php # events-to-persist or a token-free rejection
    Entity/       # GameSession — lobby READ MODEL (projection, not the aggregate)
    View/         # GameSnapshot + PlayerSeat (read models)
    Value/        # PlayerMark, GameStatus, Board (immutable 3x3)
    Exception/    # GameDomainException + CellOccupied / InvalidCell / InvalidCommand
  ReadModel/      # GameReadModel (interface) + DoctrineGameReadModel (projection)
  Actor/
    GameActor.php               # EventSourcedBehavior — DECIDE + EVOLVE, single-writer
    GameRefFactory.php          # per-id spawn/cache of the ES game actor
    Message/                    # actor-layer transport (never in Domain)
      GameEnvelope.php          # command + replyTo + originFd
      Seated.php                # join reply — snapshot + fd + token (→ private welcome)
      GameRejected.php          # failure reply — reason + fd (→ private error)
  Http/
    Handler/                    # CreateGame, ListGames, GameState, Index
    Response/                   # typed HTTP DTOs (Valinor-serialised)
    Ws/
      Intent/                   # JoinIntent / MoveIntent / ForfeitIntent / SnapshotIntent
      ClientFrameCodec.php      # frame → typed intent (never a client-supplied id)
      GameChannelActor.php      # fan-out, server-owned identity, self-passivating
      SnapshotPayload.php       # broadcast wire shape — name-only seats (no tokens)
      WelcomePayload.php        # private: {mark, token}
      WireEnvelope.php          # {type, data} wrapper for every outbound frame
      SeatView.php / ErrorPayload.php
    Routes.php                  # single place all routes are declared
    JsonExceptionRenderer.php
public/
  server.php         # config -> bootstrap -> SwooleWorkerServer::run
  dist/index.html    # React SPA (React + Babel from CDN with SRI, no toolchain)
```

## Scaling notes

- Default is **one worker process** (`TICTACTOE_THREADS=1`). The
  `WebSocketChannelActor` fans out only to sockets attached to the same
  worker; two players landing on different workers would never see each
  other. Multi-worker fan-out needs a pub/sub layer (Postgres LISTEN/NOTIFY,
  Redis, or a `nexus-cluster` transport) that is out of scope here.
- One worker is plenty for a game: Swoole coroutines multiplex thousands
  of concurrent WS connections in a single process. The interesting
  scaling story here is per-actor (single-writer per game id) — not per-CPU.
- **No connection is pinned per game.** Unlike the state-stored
  `EntityBehavior`, the event-sourced actor holds no dedicated Doctrine
  connection: the event journal is an in-memory store, and the read-model
  projection borrows a pooled EntityManager only for the duration of one
  upsert (`EntityManagerPool::withEntityManager`). Swap the journal to
  `DbalEventStore` for durability; the write side then appends to
  `nexus_event_journal` and the per-id sequence column rejects a concurrent
  cross-worker append (`ConcurrentModificationException`).

## Resilience notes

- **Client self-heals.** The SPA reconnects with capped backoff on any
  socket close and replays its stored token to reclaim the seat, and a
  per-move watchdog surfaces "server not responding" and forces a
  reconnect if a move goes unanswered — so an actor restart, worker
  recycle, or dropped connection recovers on its own instead of leaving a
  dead board.
- **Crash → replay.** Because the actor is event-sourced, recovery is just
  replay: if the game actor crashes, nexus-core supervision restarts it and
  the persistence engine folds the event log back into `GameState` before
  the next command. Events are persisted *before* the reply and projection,
  so a failure after persist never loses a move.
- **Single writer per id.** One actor owns each game's command stream, so
  moves never interleave. If a durable store is used and two workers ever
  race the same id, the journal's sequence check rejects the loser, which
  the client retries via reconnect.

## Extend it

- **Bots** — spawn a `BotPlayerActor` per game and have it play against a
  human. The bot uses the same `MakeMove` command as a human client.
- **Ranked queueing** — turn the lobby into a `MatchmakingActor` that
  pairs waiting players by rating.
- **Multi-machine** — swap `nexus-worker-pool-swoole` for a
  `nexus-cluster`-backed transport; the game actor's addressing
  (`GameRefFactory::of($gameId)`) does not change.

## License

MIT — see [LICENSE](./LICENSE).
