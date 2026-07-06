# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
- Msgpack codec (`nexus-serialization-msgpack`): `MsgpackCodec` with dual-backend dispatch — native `ext-msgpack` when available, pure-PHP `rybakit/msgpack` fallback; constructor-injectable backend selection for testing
- Serialization observability (`nexus-observability-serialization`): `TracingMessageSerializer` decorator with Internal spans, `nexus.serialization.operations` counter, `nexus.serialization.bytes`/`nexus.serialization.duration` histograms, `nexus.serialization.failures` counter, and optional PSR-3 failure warning; zero-overhead pass-through when observability is disabled and no logger is wired
