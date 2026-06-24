---
title: Upgrade Guide
related:
  - upgrade/overview
  - reference/exceptions
  - reference/attributes
---

# Upgrade Guide

Breaking changes in reverse-chronological order. Each entry describes the change and the steps required to migrate existing code.

---

### `ActorSystem::shutdown(Duration)` is now deadline-driven

`shutdown(Duration $timeout)` previously sent `PoisonPill` to all top-level actors and returned without waiting. It now enforces a hard deadline: actors that have not stopped by the time the deadline elapses are force-stopped. Any user code that called `shutdown()` and then immediately inspected actor state must add a small wait or restructure to use `PostStop` signals.

**Migrate:** No API change. If your tests shut down the system and then assert on side-effects captured inside actors, move the assertions to inside an `onSignal(PostStop)` handler or use `StepRuntime::drain()` to process all pending messages before asserting.

---

### `Mailbox::isClosed()` added to interface

The `Mailbox` interface now requires an `isClosed(): bool` method. Any custom `Mailbox` implementation (outside `nexus-core`) will fail to satisfy the interface until the method is added.

**Migrate:** Add `public function isClosed(): bool { return $this->closed; }` to each custom mailbox, where `$this->closed` is set to `true` inside your `close()` implementation.

---

### `SwooleMailbox::enqueue()` is safe outside coroutine context

Previously, calling `SwooleMailbox::enqueue()` outside a Swoole coroutine (e.g., from a timer callback or a thread boundary) threw an exception. It is now safe — the implementation falls back to a synchronous write when no coroutine context is detected.

**Migrate:** No code changes required. If you wrapped `enqueue()` calls in `Coroutine::create()` to work around the old restriction, those wrappers can be removed.

---

### `ActorSystem::spawn()` auto-prunes dead children

Spawning a child with the same name as a previously-stopped child used to throw `ActorNameExistsException`. The system now automatically prunes tombstoned children from the registry before checking for name conflicts, so you can re-spawn stopped actors by name without manual cleanup.

**Migrate:** Remove any `try/catch ActorNameExistsException` blocks that were added solely to handle re-spawn-by-name scenarios. The exception is still thrown when a *live* actor with the same name already exists.

---

### `ReceiveTimeout` lifecycle signal and `ActorContext::setReceiveTimeout()`

A new `ReceiveTimeout` signal is delivered when an actor has not received a user message for the configured duration. Enable it by calling `$ctx->setReceiveTimeout(Duration::seconds(30))` inside a behavior handler or `PreStart`. Cancel by calling `$ctx->setReceiveTimeout(Duration::zero())`.

**Migrate:** No migration required for existing actors. If you implemented idle detection manually via scheduled timers, replace with `setReceiveTimeout()` for cleaner semantics.

---

### `EntityBehavior` / `EntityRefFactory` new public surface (Doctrine ORM integration)

The `nexus-persistence-doctrine` package's `EntityBehavior` class and `EntityRefFactory` helper are now part of the public API and covered by semantic versioning. Previously they were internal and subject to change without notice.

**Migrate:** If you were using these classes via internal imports (e.g., referencing non-namespaced internals), update to the canonical `Monadial\Nexus\Persistence\Doctrine\EntityBehavior` namespace. Remove any `@internal` suppression annotations in your Psalm baseline.

---

### `#[ReplyType]` attribute and `AskReturnTypeProvider` (Psalm)

The `nexus-psalm` plugin now ships an `AskReturnTypeProvider` hook that reads `#[ReplyType(MyReply::class)]` annotations on message classes to infer the return type of `ask()` calls. Without the attribute, `ask()` return types remain `mixed`.

**Migrate:** Add `#[ReplyType(MyReply::class)]` to message classes used with the ask pattern to get precise return-type inference. No runtime behavior changes.

---

### `ParamResolver` registry for HTTP handlers

Custom `#[From*]` attribute resolvers (e.g., `#[FromSession]`) must now be registered via `HttpApp::registerParamResolver(string $attributeClass, ParamResolver $resolver)` at boot time. Resolvers that were previously hard-coded inside `ParamResolverChain` no longer run automatically.

**Migrate:** Call `$app->registerParamResolver(FromSession::class, new SessionParamResolver($sessionStore))` during application bootstrap for each custom resolver.

---

### `HttpApp::poolSingleton()` requires `PoolSingletonSpawner`

`HttpApp::poolSingleton()` now requires a `PoolSingletonSpawner` implementation to be registered. Previously the method used a built-in spawner silently; that built-in has been removed to avoid hidden actor lifecycle coupling.

**Migrate:** Implement `PoolSingletonSpawner` (typically one line: spawn a named actor and return its ref) and pass it to `HttpApp::withPoolSingletonSpawner($spawner)` before calling `poolSingleton()`.

---

### `BodySizeLimitMiddleware` constructor change

`BodySizeLimitMiddleware` now accepts a `StreamFactoryInterface` as an optional second argument (after `ResponseFactoryInterface`). The previous constructor accepted only `int $maxBytes` and `?ResponseFactoryInterface`. If you constructed it with a named `responseFactory` argument, update the call site.

**Migrate:**

```php title="Before"
new BodySizeLimitMiddleware(maxBytes: 10 * 1024 * 1024, responseFactory: $factory);
```

```php title="After"
new BodySizeLimitMiddleware(
    maxBytes: 10 * 1024 * 1024,
    responseFactory: $factory,
    streamFactory: $factory, // Psr17Factory implements both
);
```

If you relied on the default `Psr17Factory` for both, no change is required.

---

### `AuthChallenge::__construct` signature collapsed

`AuthChallenge` previously exposed separate `$scheme`, `$realm`, and `$params` constructor arguments. These are now collapsed into a single `$headerValue` string that is written verbatim to the `WWW-Authenticate` response header.

**Migrate:**

```php title="Before"
new AuthChallenge(scheme: 'Bearer', realm: 'api', params: ['error' => 'invalid_token']);
```

```php title="After"
new AuthChallenge('Bearer realm="api", error="invalid_token"');
```

---

### New Psalm rules: `MissingTransactionalDeclarationRule` and `EntityBehaviorReturnTypeProvider`

Two new rules ship in `nexus-psalm`. `MissingTransactionalDeclarationRule` warns when a method calls a persistence operation without a `#[Transactional]` declaration. `EntityBehaviorReturnTypeProvider` infers the state type of `EntityBehavior` subclasses.

**Migrate:** Run `make psalm` after upgrading to surface new violations. Suppress intentional ones with `@psalm-suppress MissingTransactionalDeclaration` on the method docblock. The `EntityBehaviorReturnTypeProvider` may resolve previously-`mixed` types to concrete types — update any downstream type assertions accordingly.
