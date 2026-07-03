# Nexus Psalm Plugin

Psalm plugin for Nexus actor system.

## Install

```bash
composer require nexus-actors/psalm
```

## Rules

- `UntypedActorRefInjection` — injected `ActorRef` params/properties must declare a concrete message type (`ActorRef<MyCommand>`); bare `ActorRef` and `ActorRef<object>` are flagged, `DeadLetterRef` and configured `<excludeRef>` classes are exempt

## Documentation

This is a read-only subtree split of [nexus-actors/nexus](https://github.com/nexus-actors/nexus).

Please refer to the main repository for documentation, issues, and pull requests.
