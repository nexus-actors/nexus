# Nexus Skeleton Rework — Design

**Date:** 2026-07-15
**Status:** Approved design, pending spec review → implementation plan

## Problem

The current `nexus-skeleton` bootstraps a project the "Java-app" way: `composer create-project` fires an interactive installer (`ProjectConfigurator`) that asks six questions (runtime / http / persistence / otel / messenger / cluster), then `BootstrapAssembler` **concatenates raw PHP string fragments** (`templates/bootstrap/*.php`) into a single monolithic `bootstrap.php`, and `PackageTrimmer` strips the unpicked packages from `composer.json` (the skeleton requires *everything* up front, then removes). A parallel web `BootstrapWizard` on the landing site does the same picking in a browser form.

This is brittle (string-template assembly), unidiomatic (monolithic bootstrap, require-everything-then-trim), and not how a modern PHP developer expects to work.

## Goals

- Generate code with **nette/php-generator** — a real code builder, no string templates anywhere.
- Bootstrap **like Symfony, not like a Java app**: a minimal skeleton grown by `composer require` + code generation, a `config/` directory, a PSR-11 autowiring container, and thin entrypoints — no monolithic `bootstrap.php`, no fat Kernel, no interactive installer.
- Follow idiomatic PHP standards (PSR-4, PER-CS, attributes, autowiring).
- Remove the website bootstrap wizard.

## Decisions (locked)

1. **Primary model:** a **maker CLI** (Symfony-MakerBundle style) — minimal skeleton, add capabilities via `composer require` + `make:`/`enable:` commands. *Not* a Flex-style Composer-plugin auto-config, and not both.
2. **Boot & wiring:** PSR-11 container + autowiring, Symfony-faithful layout (`bin/console`, `public/index.php`, `config/`, `src/`, `.env`).
3. **Container:** `symfony/dependency-injection` with **PHP** config (`config/services.php` PHP-DSL, autowire + autoconfigure, compiled + dumped) — consistent with the `symfony/console` + `symfony/messenger` already in the skeleton, and "PHP standards" (no YAML).
4. **Code generation:** `nette/php-generator` for every generated file. No string templates.

## Feasibility (validated)

A throwaway spike proved the two risky mechanics against the real Nexus classes:
- `nette/php-generator` produces textbook-idiomatic Nexus code (a `final readonly` message with a promoted param; a `final` `ActorHandler` with correct `use` statements), both `php -l`-clean.
- `symfony/dependency-injection` autowiring constructs an `ActorHandler` with an injected dependency, and `Props::fromContainer($container, Handler::class)` boots that container-built actor on `FiberRuntime`, which processed a message end-to-end.

One constraint surfaced: symfony/di reflects services **through the autoloader**, so generated classes must live in PSR-4 `src/` (they do) — inline/non-autoloadable classes fail reflection.

## Deliverables

### 1. `nexus-actors/maker` (new package, dev dependency)

The generator. `symfony/console` `#[AsCommand]` classes whose only code-writing mechanism is nette/php-generator. Versioned/split like every other package. The skeleton requires it under `require-dev`; `bin/console` registers its commands when present (a `class_exists` guard — MakerBundle-in-dev analog).

### 2. `nexus-actors/skeleton` (reworked from scratch)

Minimal Symfony-style app. Requires **only** `nexus-actors/core` + one runtime (`runtime-fiber` default). Everything below `installer/`, `templates/bootstrap/*`, `bootstrap.php`, and the deleted `Kernel` is removed and replaced.

**Layout:**
```
my-app/
├── bin/console            # symfony/console: app commands + make:*/enable:* (dev)
├── public/index.php       # HTTP front controller (created by enable:http)
├── config/
│   ├── services.php       # symfony/di PHP-DSL: ->autowire()->autoconfigure() over src/
│   └── packages/          # one small file per capability (runtime.php, persistence.php, …)
├── src/
│   ├── Kernel.php         # thin (~40 lines)
│   ├── Actor/  Message/   # (mostly generated) app code
├── var/cache/             # compiled + dumped container
├── .env
└── composer.json          # core + one runtime only
```

**Boot flow:** `bin/console` and `public/index.php` both instantiate `App\Kernel`, which loads `.env`, builds the container from `config/services.php` + `config/packages/*.php`, compiles and dumps it to `var/cache` (PhpDumper; rebuilt on change in dev), then `boot()` creates the `ActorSystem` with the runtime from config, spawns the registered actors, and runs.

### 3. Website removal

Delete `landing/src/components/BootstrapWizard.tsx`, `landing/src/lib/bootstrapConfig.ts`, `landing/src/pages/bootstrap.astro`; remove the `/bootstrap` nav link; replace the CTA with the `create-project` one-liner + a docs link.

## Actor registration — `#[AsActor]` autoconfigure

- `#[AsActor(name: 'greeter')]` attribute (lives in **`nexus-app`**, the bootstrap package) on any `ActorHandler`.
- The Kernel calls `registerAttributeForAutoconfiguration(AsActor::class, …)`, tagging every attributed handler `nexus.actor` with its name.
- A compiler pass (`ActorPass`) collects the tagged services into an `ActorRegistry` (name → service id).
- At boot the Kernel iterates the registry: `system->spawn(Props::fromContainer($container, $class), $name)`.

Net effect: `make:actor` drops one `#[AsActor('…')]`-attributed class into `src/Actor/` and it is auto-spawned — nothing to hand-wire. Direct analog of Symfony's `#[AsCommand]` / `#[AsMessageHandler]`, and exactly what the spike validated.

**Correctness detail:** actor-handler services are registered **non-shared** (prototype scope, `->setShared(false)`), so every `spawn` — including dynamically-spawned children of the same handler class — gets a *fresh* instance. Sharing a handler instance across actors would share mutable state and break the actor model. (The spike used a single shared actor, which is why it passed; the general rule is non-shared.)

## Command surface (v1)

**`make:*` — scaffold app code** (all via nette/php-generator):

| Command | Generates | Options |
|---|---|---|
| `make:actor <Name>` | `src/Actor/<Name>Actor.php` — `#[AsActor]` + `handle()` | `--stateful` → `StatefulActorHandler`; `--message=Foo` typed arm |
| `make:message <Name>` | `src/Message/<Name>.php` — `final readonly` | `--remote` adds `#[MessageType]` |
| `make:controller <Name>` | `src/Http/<Name>Controller.php` (+ route entry) | — |
| `make:command <Name>` | `src/Command/<Name>Command.php` — `#[AsCommand]` | — |

**`enable:*` — turn on a capability** (each: `composer require` the package(s) **and** generate `config/packages/<feature>.php` + minimal scaffolding, via nette):

- `enable:http` → `nexus-http` (+ `http-server-swoole`); `public/index.php`, `config/packages/http.php`, sample controller.
- `enable:persistence dbal|doctrine` → the store package; `config/packages/persistence.php` + a sample event-sourced actor.
- `enable:cluster` → `nexus-cluster-tcp` (+ `runtime-swoole`); `config/packages/cluster.php`.
- `enable:messenger` → `nexus-messenger` (+ console); `config/packages/messenger.php`.
- `enable:otel` → `nexus-observability-otel`; `config/packages/observability.php`.
- `enable:runtime swoole|worker-pool` → swap `config/packages/runtime.php` + require the runtime.

The old flow ("answer 6 questions → concatenate fragments → trim deps") becomes: `composer create-project nexus-actors/skeleton myapp` (minimal, fiber) → `enable:http` / `enable:persistence dbal` as needed → `make:actor OrderProcessor`. Every step is explicit, re-runnable, and diff-able.

## Documentation

- Rewrite `website/docs/getting-started/quick-start.md` (and `installation.md` as needed) to the create-project → `enable:*` → `make:*` flow.
- Add `website/docs/packages/maker.md` (+ sidebar entry) documenting every `make:`/`enable:` command.
- Rewrite the skeleton's `README.md` to the maker model.
- Update `getting-started/module-selector.md` to the `enable:*` model; scrub any installer/wizard mentions.

## Non-goals (YAGNI)

- No Flex-style Composer-plugin auto-configuration (Q1 option B, not chosen).
- No separate `make:handler` (it is `make:actor --stateful`).
- `enable:*` covers exactly the current installer's feature set — nothing speculative.

## Migration

- **Close PR #59** — its changes modify the wizard and add the old installer-skeleton, both of which this rework deletes.
- Delete the wizard files from `main`.
- Replace `packages/nexus-skeleton` contents wholesale; add `packages/nexus-maker`.
- Add `nexus-actors/maker` to the split matrix; create the `nexus-actors/maker` split repo.

## Testing

- **maker**: unit tests per command asserting the generated file's content/structure (parse the nette output or snapshot it) and that generated code is `php -l`-clean and passes the project's own cs/psalm rules.
- **skeleton**: an integration test that boots the generated `Kernel`, autowires an `#[AsActor]` handler, spawns it, and asserts a message is processed (the spike, promoted to a real test).
- `#[AsActor]` + compiler pass: unit test the pass builds the registry from tagged services.
