# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed
- Cancelling a repeating Swoole timer now works after the first fire (audit REL-005). `scheduleRepeatedly()` returned a handle bound to the initial `Timer::after` ID; once the first invocation fired, the recurring `Timer::tick` had a different ID and `cancel()` cleared a dead timer, leaving the tick running forever (stopped/restarted actors retained ticks). The handle is now retargeted to the live timer at the after→tick handover, and `DeferredCancellable` (timers scheduled before `run()`) delegates cancellation to the materialised timer instead of only flagging itself.
- WebSocket connections are now authorized BEFORE the 101 upgrade (audit SEC-001). A new pre-upgrade `HandshakeGate` runs the WebSocket middleware pipeline — the same `AuthenticationMiddleware`/`AuthorizationMiddleware` used on HTTP routes — against the upgrade request; missing/invalid/expired/under-scoped credentials are rejected with plain HTTP 401/403 on the not-yet-upgraded connection, and unmatched paths with 404. New DSL: `WsApplication::wsMiddleware()` (global WS middleware) and per-route middleware on `ws()`/`channel()`; `paramResolver()` now also feeds the WebSocket `HandlerInstantiator`, so `#[FromPrincipal]` resolves the same principal that authorized the handshake. The Swoole servers switched from the post-upgrade `Open` event to a custom RFC 6455 `handshake` handler that performs the accept and dispatches open with the authorized request. Previously, WebSocket routes documented with auth were reachable without any auth pipeline.
- `Props::fromFactory()`, `Props::fromStatefulFactory()`, and `Props::fromContainer()` now enforce their handler contracts with an explicit `InvalidPropsFactoryException` (wrapped in `ActorInitializationException` at spawn) instead of `assert()` (audit DSL-008). Production deployments running `zend.assertions=-1` previously deferred wrong factory results into obscure downstream failures such as `Call to undefined method stdClass::initialState()`; they now fail at actor start with the factory and actual type named.
- `#[FromActor]` parameters are now type-checked at HTTP compile time (audit DSL-009): a parameter whose declared type cannot hold an `ActorRef` (scalar, incompatible class, union without `ActorRef`) is rejected with `InvalidFromActorParameterException` when the handler is compiled, instead of compiling successfully and failing with a `TypeError` on first invocation. `ActorRef`, nullable, union-with-`ActorRef`, `object`, `mixed`, and untyped parameters remain valid.
- Swoole mailbox admission is now truthful (audit REL-001): every `Channel::push()` result is inspected, so a failed push can no longer be reported as `Accepted` and silently lost. "Unbounded" mailboxes expose their real 65,536-slot physical capacity via `SwooleMailbox::$effectiveCapacity`, report `isFull()` honestly, and throw `MailboxOverflowException` at the physical cap; `DropOldest` reports `Dropped` when the swap fails instead of claiming acceptance. `LocalActorRef::ask()` now fails the returned future immediately with the new `AskUndeliverableException` when the mailbox drops or backpressures the ask message, instead of making the caller wait out the full ask timeout.
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
