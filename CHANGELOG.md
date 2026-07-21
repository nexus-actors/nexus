# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- Persistence side-effect hooks (`thenRun()`/`thenReply()`) now execute on every effect instead of being silently dropped off the persist path (audit DDD-001). On `Effect::persist(...)`/`DurableEffect::persist(...)` they keep running after the durable write with the new state; on `none()`, `unhandled()`, `reply()`, `stash()`, and `stop()` they now run after the effect's primary action with the unchanged current state, making `Effect::none()->thenReply(...)` the canonical read-only query. Code that relied on hooks being dropped on non-persist effects must remove those hooks.

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
