# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- Cancelling a repeating Swoole timer now works after the first fire (audit REL-005). `scheduleRepeatedly()` returned a handle bound to the initial `Timer::after` ID; once the first invocation fired, the recurring `Timer::tick` had a different ID and `cancel()` cleared a dead timer, leaving the tick running forever (stopped/restarted actors retained ticks). The handle is now retargeted to the live timer at the after→tick handover, and `DeferredCancellable` (timers scheduled before `run()`) delegates cancellation to the materialised timer instead of only flagging itself.
- `Props::fromFactory()`, `Props::fromStatefulFactory()`, and `Props::fromContainer()` now enforce their handler contracts with an explicit `InvalidPropsFactoryException` (wrapped in `ActorInitializationException` at spawn) instead of `assert()` (audit DSL-008). Production deployments running `zend.assertions=-1` previously deferred wrong factory results into obscure downstream failures such as `Call to undefined method stdClass::initialState()`; they now fail at actor start with the factory and actual type named.
- Persistence side-effect hooks (`thenRun()`/`thenReply()`) now execute on every effect instead of being silently dropped off the persist path (audit DDD-001). On `Effect::persist(...)`/`DurableEffect::persist(...)` they keep running after the durable write with the new state; on `none()`, `unhandled()`, `reply()`, `stash()`, and `stop()` they now run after the effect's primary action with the unchanged current state, making `Effect::none()->thenReply(...)` the canonical read-only query. Code that relied on hooks being dropped on non-persist effects must remove those hooks.
- HTTP route handlers given as a `'#name'` actor shorthand string now fail at compile time with `ActorShorthandHandlerException` and an actionable message pointing to the supported `#[FromActor('name')]` injection, instead of a cryptic reflection error (audit DSL-002). The shorthand was never implemented; `HttpApp` docblocks now show the real actor-backed route pattern.
- `HttpApp::compile()` is now terminal and idempotent (audit DSL-003, DSL-006): the first call freezes the DSL and spawns worker-local/pool-singleton actors exactly once; repeated calls reuse the frozen state and live actor table instead of respawning actors and throwing `ActorNameExistsException`. Registering routes, actors, middleware, or configuration after compilation now throws `HttpAppAlreadyCompiledException` instead of being silently ignored.

## [0.1.0] - 2026-07-21

### Added
- Initial actor system with Fiber and Swoole runtimes
- Supervision trees with one-for-one and all-for-one strategies
- Persistence and event sourcing with in-memory, DBAL, and Doctrine backends
- Multi-process clustering via Swoole
- Psalm plugin for generic actor type inference
- Symfony Messenger bridge (`nexus-messenger`): MessengerActorRef/MessengerGateway producers, supervised ReceiverActor with backpressure-aware ack, pluggable MessageRouter, Nexus-backed Messenger serializer, and LifecycleWatchdog worker recycling
- Symfony Console runners (`nexus-messenger-console`): `nexus:messenger:consume` and `nexus:messenger:produce` commands with limit/memory/time watchdog wiring
- Threaded Swoole console adapter (`nexus-messenger-console-swoole`): `nexus:messenger:consume-threads` command using the Swoole worker pool; each thread owns an independent transport connection and actor system; limits are per-thread
- Broker request/reply (`nexus-messenger`): `MessengerActorRef::ask()` enabled via `AskSupport` (wired with `MessengerBridge::askSupport()`); `TransportReplyChannelFactory` with Ephemeral/DeleteOnShutdown/Persistent lifecycle; `MapReplySenderLocator` for SSRF-safe reply routing; `ReceiverActor` process-ack path with configurable `askPendingTimeout`; interop via `X-Nexus-Correlation-Id` / `X-Nexus-Reply-To` headers
- Project quickstart: `composer create-project nexus-actors/skeleton` with the interactive `nexus:setup` wizard (runtime, modules, application architecture preset)
- Code generators (`nexus-actors/maker`): `make:actor` (handler, functional, stateful, event-sourced, durable-state types) and `make:message`, built on nette/php-generator
- All 41 packages split to read-only repositories and published on Packagist

### Changed
- Internal cross-package constraints moved from `dev-main` to `^0.1`
