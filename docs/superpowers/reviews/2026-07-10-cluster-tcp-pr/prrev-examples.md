# PR Review — EXAMPLES dimension (feat/cluster-tcp, HEAD 6c343f2b vs main)

## Verdict: request-changes

One genuine runtime bug (TypeError on a documented, headline command) is enough to block
merge on its own, plus one real-but-lower-severity anti-pattern. Everything else — API usage,
Docker wiring, PSR-14/PSR-3 integration, doc claims about failure detection timing, the
alphabetized-log-context regression check — verified clean against the actual package source.

## Findings

### High — `bin/console nexus:messenger:produce` throws TypeError (examples/nexus-messenger-redis/bin/console:55-58, 108)

```php
$serializer = new NexusMessengerSerializer(
    SerializerFactory::fromEnvironment($typeRegistry, [OrderPlaced::class]),
    $typeRegistry,
);
...
$app->addCommand(new ProduceCommand($transport, $serializer, $typeRegistry));
```

`NexusMessengerSerializer` implements Symfony Messenger's `SerializerInterface`
(`packages/nexus-messenger/src/Serialization/NexusMessengerSerializer.php:47`), not Nexus's own
`Monadial\Nexus\Serialization\MessageSerializer` (`serialize()`/`deserialize()`). But
`ProduceCommand::__construct` requires the latter as its 2nd param
(`packages/nexus-messenger-console/src/ProduceCommand.php:45-49`:
`SenderInterface $sender, MessageSerializer $serializer, TypeRegistry $types`). Passing the
`NexusMessengerSerializer`-wrapped value is a straight type mismatch — running the documented
command from the README (`php bin/console nexus:messenger:produce order-placed '{...}'`) will
throw a `TypeError` at construction. This is one of the two documented invocation paths in the
README's "Quick start" section, so it is not a corner case.

**Fix:** keep the inner serializer in its own variable and pass that to `ProduceCommand`:
```php
$innerSerializer = SerializerFactory::fromEnvironment($typeRegistry, [OrderPlaced::class]);
$serializer = new NexusMessengerSerializer($innerSerializer, $typeRegistry);
...
$app->addCommand(new ProduceCommand($transport, $innerSerializer, $typeRegistry));
```

### Medium — `exit(1)` inside a Swoole coroutine throws an uncaught fatal instead of clean exit (examples/nexus-cluster-tcp/bin/node.php, the two validation branches under NODE_ROLE/GREETER_NODE)

Both validation failures (bad `GREETER_NODE` format, unknown `NODE_ROLE`) call `exit(1)` from
inside the `scheduleOnce(Duration::millis(10), ...)` closure, which only runs once
`$system->run()` has entered Swoole's coroutine context. Empirically verified in this
environment (`docker compose exec -T php-swoole php -r '...'`, Swoole 6.2.1 ZTS):

```
before exit

Fatal error: Uncaught Swoole\ExitException: swoole exit in Command line code:4
...
PHP exit code: 255
```

So the process does terminate — it does **not** hang, which satisfies the specific regression
check this review was asked to verify — but it (a) exits with code 255 instead of the intended
1, and (b) prints an ugly uncaught-exception stack trace to stderr instead of the clean
`$logger->error(...)` line that precedes it, undermining the "fail fast with a clear message"
intent the code comments describe. A misconfigured `docker compose up` looks like a crash, not
a validated config error.

**Fix:** move the `NODE_ROLE`/`GREETER_NODE` validation (pure string parsing, no runtime
dependency) to top-level script scope, before `ActorSystem::create()` / before entering
`scheduleOnce`, so `exit(1)` fires outside any coroutine.

### Low — README env-var table omits `REPLY_STREAM` (examples/nexus-messenger-redis/README.md, "Environment variables" section)

`REPLY_STREAM` is used in `bin/console:65` and mentioned in the ask/reply prose section, but
missing from the tabulated environment-variable reference — a reader following only the table
would not know this variable exists for the console-based ask/reply demo.

## Regression checks (explicitly requested)

- **`exit(1)` on bad `NODE_ROLE`/`GREETER_NODE` — no infinite hang:** confirmed no hang (process
  terminates via `Swoole\ExitException`), but see Medium finding above — it's not a *clean*
  exit either.
- **Alphabetized log-context keys:** verified across every touched/new log call —
  `bin/node.php:183-187` (`advertise, bind, role, seeds, self`), `bin/node.php:322-323`
  (`node, role`), `bin/worker.php:145-148` (`message_limit, receiver_count, redis_dsn, stream`),
  `OrderProcessor.php:27-30` (`actor, amount_cents, customer_id, order_id`) — all correctly
  sorted. Clean.
- **messenger-redis standalone-deps comment:** README's "Note" that the root Nexus CI image
  lacks `ext-redis` is accurate (root `docker/Dockerfile` has zero `redis` references) and the
  example ships its own `Dockerfile` installing `ext-redis` via PECL — comment is honest, not
  aspirational.

## API/signature verification (all confirmed correct against real package source)

- `ClusterNode::boot($system, $topology, $typeRegistry, logger: $logger)` matches
  `packages/nexus-cluster-tcp/src/ClusterNode.php:187-194` exactly (named `logger:` correctly
  skips nullable `$transport`/`$observability`).
- `ClusterTopology::create(...)`, `withHeartbeatInterval`, `withGossipInterval`,
  `withFailureDetection(maxNoHeartbeat:, minStdDev:, phiThreshold:)` all match real signatures.
- `ClusterRef::ask(...)->await()` — `Future::await(): object` confirmed at
  `packages/nexus-runtime/src/Async/Future.php:92`.
- `NodeAddress`, `NodeEndpoint::fromString`, `ClusterView::upNodes()`, `queryViewAsync` all
  match.
- PSR-14 event field names (`NodeUp->node/endpoint`, `NodeDown->node`,
  `NodeSuspected->node/reason`, `PeerConnected->peer/endpoint`, `PeerDisconnected->peer`) all
  match `StdoutEventDispatcher.php`'s property access exactly.
- `SuspicionReason` enum (`packages/nexus-cluster-tcp/src/Membership/SuspicionReason.php:20-24`)
  has exactly `Connection, Gossip, Phi` — matches README table and dispatcher code.
- Docker: `docker/Dockerfile`'s `php-swoole` target (line 96) is `base-zts` = `php:8.5.7-zts`
  (line 23), Swoole built at tag `v6.2.1` (line 82) — README's exact version claims ("PHP
  8.5.7", "Swoole 6.2.1") are correct, not just plausible.
- `php -l` clean on `bin/node.php` and all `src/*.php` files (PHP 8.5.7 ZTS, via
  `docker compose exec -T php-swoole`).
- composer.json PSR-4 path-repository mappings (both examples) correctly resolve to
  `/nexus-packages/<pkg>/src/` matching each real package's own psr-4 declaration, including
  the easy-to-miss `Monadial\Nexus\Runtime\` → `nexus-runtime` (not `-fiber`/`-swoole`).
- `MessengerBridge::producer`, `::spawnReceivers` (11-param signature, all 10 positional args in
  `bin/worker.php` land correctly, trailing `replySenders` correctly omitted), `::watchdogProps`,
  `LifecycleThresholds::none()->withMessageLimit()`, `ReceiverActorConfig::default()
  ->withPollInterval()` — all match `packages/nexus-messenger/src/MessengerBridge.php`.
- `ConsumeCommand`'s named-arg call (`logger:`, `events:`, `replySenders:`) matches
  `packages/nexus-messenger-console/src/ConsumeCommand.php:75-83` param names exactly.
- `MapReplySenderLocator`, `NexusMessengerSerializer($inner, $typeRegistry)` ordering,
  `PhpNativeSerializer(allowedClasses:)`, `MessagePackMessageSerializer($registry)`,
  `TracingMessageSerializer($serializer, $observability)`, `Observability::isEnabled()` — all
  confirmed correct.
- `Connection::fromDsn($dsn . '/' . $stream, ['group' => $group])` — correct real Symfony Redis
  Messenger API: DSN path segment after the host becomes the stream name, `group`/`consumer`
  fall back to the options array.
- `composer.json` requires `symfony/redis-messenger` (correct real Packagist name) and
  `ext-redis`, consistent with the example's own `Dockerfile` installing phpredis via PECL.
- Wallet-app / tictactoe diffs are pure Psalm-generics polish (`ActorRef<object>` →
  `ActorRef<ConcreteType>`, new `WalletCommand` marker interface implemented by
  `Deposit`/`Withdraw`/`GetBalance`). All referenced types (`GameRejected`, `Seated`,
  `GameSnapshot`, `WalletCommand`) exist, are correctly imported, and namespaces resolve. Low
  risk, consistent with the project's "Generic Type Safety" philosophy documented in CLAUDE.md.

## Strengths

- The cluster-tcp README is unusually honest: it calls out the exact previously-fixed
  recv-timeout false-Suspect bug, explicitly scopes what the demo does and doesn't reproduce
  (saturation-induced transient Suspect vs. false Down), and links to the tracked root-cause
  plan doc rather than hiding the caveat. No faked output — all "what to observe" log excerpts
  are clearly framed as illustrative, timestamps redacted to `[HH:MM:SS]`.
  "Startup and join" is genuinely illuminating and reproducible.
- Both examples correctly demonstrate the headline idioms: `final readonly` messages with
  `#[MessageType]`, `ClusterNode::boot()` before `$system->run()`, location-transparent
  `tell`/`ask`, PSR-14 membership events, graceful shutdown via SIGTERM/Leave-frame, and a
  clearly-marked "honest limitations" section (no receptionist, AP-only, no rejoin, no TLS
  demoed) rather than overselling scope.
- messenger-redis's ask/reply section explicitly documents the SSRF hardening rationale
  (reply-to header is a lookup key, never a DSN) — teaches the security-conscious pattern, not
  just the happy path.
- Both `composer.json` files carry an explicit `_comment` describing exactly how to convert the
  example from monorepo path-repositories to real Packagist requires — good hygiene for a
  "copy this folder out" example.
