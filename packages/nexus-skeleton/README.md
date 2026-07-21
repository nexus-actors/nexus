# Nexus Skeleton

Start a [Nexus](https://nexusactors.com) actor-system project in three commands:

```bash
composer create-project nexus-actors/skeleton my-app --stability=dev
cd my-app
bin/console run
```

`create-project` launches an interactive setup wizard (`nexus:setup`) that picks your
runtime (Fiber for development, Swoole for production) and optional modules —
persistence, OpenTelemetry observability, TCP clustering, and the Symfony Messenger
bridge. Modules marked *experimental* are pre-1.0: not production-ready, APIs may change.

## Everyday commands

```bash
bin/console make:actor Payment --with-message   # generate src/Actor/PaymentActor.php + message
bin/console make:actor Ticker --functional      # closure-based actor (Behavior::receive factory)
bin/console make:message OrderPlaced            # generate src/Message/OrderPlaced.php
bin/console run                                 # boot the actor system (alias of nexus:run)
bin/console nexus:setup                         # re-run the wizard to add modules later
```

## Project layout

```
bin/console            command-line entry point
config/services.php    DI container config (autowires src/)
config/packages/       per-module config (runtime.php picks your Runtime)
src/Actor/             #[AsActor] handlers — auto-spawned at boot
src/Message/           message classes
src/Kernel.php         boots the container and the ActorSystem
```

Actors are plain classes with `#[AsActor('name')]` — the Kernel spawns every tagged
handler at boot. See the [Quick Start](https://docs.nexusactors.com/docs/getting-started/quick-start)
for a guided tour.
