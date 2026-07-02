# nexus-tictactoe

Multiplayer tic-tac-toe over WebSocket. A single-file React SPA talks to a
Nexus Swoole-Threads HTTP server; every game is a persisted Doctrine
aggregate driven by a per-id `EntityBehavior` actor. Broadcast between the
two players (and any spectators) goes through a `WebSocketChannelActor`
keyed by game id.

## What it shows

- **`EntityBehavior` as a rich aggregate** — the `GameSession` entity holds
  every rule (whose turn, cell occupied, game over) and the actor is a thin
  command dispatcher that flushes on each move. Single-writer per game id.
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
  `EntityRefFactory::of($id)`; the shared Postgres row is the source of
  truth, so any worker thread can serve any game.

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
  GET  /api/games       ─▶ HTTP handler ─▶ Doctrine EM (pool)
  POST /api/games       ─▶ CreateGameHandler ─▶ persist row
  WS   /ws/games/{id}   ─▶ GameChannelActor  ─▶ GameActor
                                      │            │  (EntityRefFactory::of)
                                      │            ▼
                                      │       GameSession (Doctrine entity)
                                      ▼            │
                                  broadcast    flush on every move
                                  snapshot         │
                                  to all       Postgres `games` row
                                  attached
                                  sockets
```

- The **game actor** is spawned lazily by `EntityRefFactory::of($gameId)`.
  It handles the sealed `GameCommand` set (`JoinGame`, `MakeMove`,
  `Forfeit`, `GetSnapshot`) and returns an `EntityEffect` on every
  transition — the entity flushes to Postgres before the reply goes out.
- The **channel actor** decodes each client JSON frame into a typed
  command, forwards it to the game actor with `$ctx->self()` as the reply
  target, then caches and broadcasts each `GameSnapshot` that comes back.
- If both players idle for 5 minutes the game actor **passivates** — the
  next command from `EntityRefFactory::of()` reloads the row and resumes.

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
    DoctrineKit.php    # pools + EntityRefFactory (the only Doctrine wiring)
    SchemaBootstrap.php
  Domain/         # framework-agnostic — no imports from Actor/ or Http/
    Command/      # marker + JoinGame / MakeMove / Forfeit / GetSnapshot
    Entity/       # GameSession Doctrine aggregate — every rule lives here
    View/         # GameSnapshot + PlayerSeat (read models)
    Value/        # PlayerMark, GameStatus, Board (immutable 3x3)
    Exception/    # GameFullException, NotYourTurnException, ...
  Actor/
    GameActor.php               # EntityBehavior handler — single-writer per game id
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
  other. The `#[Version]` column on `GameSession` keeps the persistence
  layer safe, but multi-worker fan-out needs a pub/sub layer (Postgres
  LISTEN/NOTIFY, Redis, or a `nexus-cluster` transport) that is out of
  scope for this example.
- One worker is plenty for a game: Swoole coroutines multiplex thousands
  of concurrent WS connections in a single process. The interesting
  scaling story here is per-actor (single-writer per game id, passivate
  when idle) — not per-CPU.

## Extend it

- **Bots** — spawn a `BotPlayerActor` per game and have it play against a
  human. The bot uses the same `MakeMove` command as a human client.
- **Ranked queueing** — turn the lobby into a `MatchmakingActor` that
  pairs waiting players by rating.
- **Multi-machine** — swap `nexus-worker-pool-swoole` for a
  `nexus-cluster`-backed transport; the game actor's addressing
  (`EntityRefFactory::of($gameId)`) does not change.

## License

MIT — see [LICENSE](./LICENSE).
