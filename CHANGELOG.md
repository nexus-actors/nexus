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
