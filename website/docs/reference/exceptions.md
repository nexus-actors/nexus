---
title: Exception catalog
related:
  - reference/config
  - core-concepts/supervision
  - reference/gotchas
  - reference/lifecycle-signals
---

# Exception catalog

Every typed exception class across all Nexus packages is listed here. Entries are grouped by package and sorted alphabetically within each group. The base class hierarchy is:

- `NexusException extends RuntimeException` — checked runtime exceptions; declare in `@throws`.
- `NexusLogicException extends LogicException` — programmer errors and invariant violations; not tracked by Psalm's checked exception analysis.
- Package-specific bases (`MailboxException`, `SerializationException`, `AuthException`, `HttpException`) extend one of the two above.

---

## nexus-core

### ActorException

`Monadial\Nexus\Core\Exception\ActorException`

Abstract base for actor runtime exceptions. Extends `NexusException`. Catch this to handle any actor-layer failure without enumerating subclasses.

### ActorInitializationException

`Monadial\Nexus\Core\Exception\ActorInitializationException`

**When thrown:** `Behavior::setup()` factory throws, or the actor cell fails during the `New → Starting` transition.

**Cause:** The behavior factory or `onPreStart` logic threw an exception before the actor was ready to process messages.

**Recovery:** Inspect `$e->actor` (the `ActorPath`) and `$e->reason` to identify which actor failed. Fix the initialization logic or catch the exception in the behavior factory and return a fallback behavior. The parent actor receives a `ChildFailed` signal.

**See also:** [Lifecycle signals — ChildFailed](./lifecycle-signals.md#childfailed)

### ActorNameExistsException

`Monadial\Nexus\Core\Exception\ActorNameExistsException`

Extends `NexusLogicException`.

**When thrown:** `$ctx->spawn(Props, $name)` is called when a child with `$name` already exists under the same parent.

**Cause:** Two `spawn()` calls use the same name string within the same parent actor, or a respawn is attempted while the previous child is still alive.

**Recovery:** Use `$ctx->child($name)` to check whether the child already exists before spawning. Alternatively, use `$ctx->spawnAnonymous()` to avoid naming collisions. Names must be unique per parent.

**See also:** [Gotchas — child-name uniqueness](./gotchas.md#child-names-must-be-unique-per-parent)

### AskTimeoutException

`Monadial\Nexus\Core\Exception\AskTimeoutException`

Extends `ActorException` and implements `FutureTimeoutException`.

**When thrown:** The future returned by `ActorRef::ask()` is not resolved within the specified `Duration`.

**Cause:** The target actor did not reply within `$timeout`. The actor may be overloaded, stopped, or the handler did not call `$ctx->reply()`.

**Recovery:** Check `$e->target` and `$e->timeout`. Increase the timeout if the actor is legitimately slow. If the actor may be dead, check `$ref->isAlive()` before calling `ask()`. Note that asking a dead actor produces a timeout, not an immediate exception.

**See also:** [Gotchas — ask to dead actor](./gotchas.md#ask-to-a-dead-actor-times-out-not-throws)

### InvalidActorPathException

`Monadial\Nexus\Core\Exception\InvalidActorPathException`

Extends `NexusLogicException`.

**When thrown:** An actor path string fails validation (illegal characters, missing leading slash, or malformed segments).

**Cause:** A hard-coded or dynamically assembled path string does not conform to the path grammar `akka://system-name/user/parent/child`.

**Recovery:** Check `$e->invalidPath`. Actor names may only contain letters, digits, hyphens, and underscores. System names follow the same rules.

### InvalidActorStateTransition

`Monadial\Nexus\Core\Exception\InvalidActorStateTransition`

Extends `NexusLogicException`.

**When thrown:** The actor cell receives a state-machine event that is illegal from its current state (e.g., a `Suspend` while already `Stopped`).

**Cause:** Internal invariant violation — usually triggered by sending internal system messages directly from user code, or by a runtime bug.

**Recovery:** Do not send `Suspend`, `Resume`, or `Kill` from user code. If you see this in production, file an issue — it indicates a runtime bug.

**See also:** [System messages — Suspend/Resume](./system-messages.md#suspend-and-resume)

### InvalidBehaviorException

`Monadial\Nexus\Core\Exception\InvalidBehaviorException`

Extends `NexusLogicException`.

**When thrown:** A behavior handler returns an invalid value (e.g., `null`).

**Cause:** Handler returned something other than a `Behavior` instance. Psalm level-1 catches this at analysis time; this exception is the runtime fallback.

**Recovery:** Return `Behavior::same()`, `Behavior::stopped()`, or a new behavior from every handler path. Never return `null`.

### MaxRetriesExceededException

`Monadial\Nexus\Core\Exception\MaxRetriesExceededException`

Extends `NexusException`.

**When thrown:** A child actor has been restarted `$maxRetries` times within `$window` and still fails. The supervision strategy stops the child and propagates this exception to the parent.

**Cause:** Persistent failure — the child crashes on every restart, indicating a structural problem rather than a transient error.

**Recovery:** Inspect `$e->child`, `$e->maxRetries`, `$e->window`, and `$e->lastFailure`. Address the root cause. Consider using `SupervisionStrategy::exponentialBackoff()` to add delay between restart attempts.

**See also:** [Config — exponentialBackoff](./config.md#supervisionstrategyexponentialbackoff)

### NexusException

`Monadial\Nexus\Core\Exception\NexusException`

Abstract base. Extends `RuntimeException`. All checked Nexus exceptions extend this. Catch `NexusException` to handle any typed Nexus failure in one place.

### NexusLogicException

`Monadial\Nexus\Core\Exception\NexusLogicException`

Abstract base. Extends `LogicException`. All programmer-error exceptions extend this. These represent bugs and invariant violations; do not catch and swallow them.

### NoSenderException

`Monadial\Nexus\Core\Exception\NoSenderException`

Extends `ActorException`.

**When thrown:** The handler calls `$ctx->reply($msg)` (or `$ctx->sender()`) on a message that was sent via `tell()` — not `ask()`.

**Cause:** `tell()` does not set a sender or reply-to ref. Only `ask()` creates a reply channel.

**Recovery:** Guard with `$ctx->sender()->isDefined()` before calling `reply()`. If the handler is used for both fire-and-forget and request-reply messages, branch on the sender option.

**See also:** [Gotchas — reply() asymmetry](./gotchas.md#reply-only-works-after-ask)

### StashOverflowException

`Monadial\Nexus\Core\Exception\StashOverflowException`

Extends `NexusException`.

**When thrown:** `$ctx->stash()` is called and the stash buffer has reached capacity.

**Cause:** The actor is stashing faster than it is unstashing. Default stash capacity is 100 messages.

**Recovery:** Check `$e->capacity` and `$e->size`. Either increase stash capacity by configuring `Props::withMailbox()` with a larger bounded mailbox, reduce the rate of stashing, or call `$ctx->unstashAll()` more aggressively.

**See also:** [Gotchas — stash capacity](./gotchas.md#stash-capacity-defaults-to-100)

---

## nexus-runtime

### FutureCancelledException

`Monadial\Nexus\Runtime\Exception\FutureCancelledException`

Implements `FutureException` (extends `RuntimeException`).

**When thrown:** A `Future` is explicitly cancelled before it resolves.

**Cause:** The caller cancelled the future via `cancel()` or the runtime shut down with pending futures.

**Recovery:** Wrap `->await()` in a try/catch for `FutureCancelledException`. If the future represents a cancelled `ask`, the reply was never sent.

### FutureException

`Monadial\Nexus\Runtime\Exception\FutureException`

Marker interface. Extends `Throwable`. Implemented by `FutureCancelledException` and `FutureTimeoutException` (the latter also implemented by `AskTimeoutException`). Catch `FutureException` to handle any future failure.

### FutureTimeoutException

`Monadial\Nexus\Runtime\Exception\FutureTimeoutException`

Marker interface. Extends `FutureException`. Implemented by `AskTimeoutException`. Represents a future that exceeded its deadline.

### InvalidMailboxConfigException

`Monadial\Nexus\Runtime\Exception\InvalidMailboxConfigException`

Extends `LogicException`.

**When thrown:** A `MailboxConfig` is constructed with invalid parameters (e.g., a negative capacity).

**Cause:** Programmer error — passing an out-of-range value to `MailboxConfig::bounded()`.

**Recovery:** Validate capacity values before construction. Capacity must be a positive integer.

### MailboxClosedException

`Monadial\Nexus\Runtime\Exception\MailboxClosedException`

Extends `MailboxException`.

**When thrown:** `Mailbox::enqueue()` or `Mailbox::dequeue()` is called after the mailbox has been closed (actor stopped).

**Cause:** A message was sent to an actor that has already stopped. The `tell()` call races with the actor's shutdown.

**Recovery:** Check `$ref->isAlive()` before sending, or catch `MailboxClosedException` at the send site and route to dead letters manually. The HTTP default mapper returns `503` when this exception escapes a handler.

### MailboxException

`Monadial\Nexus\Runtime\Exception\MailboxException`

Abstract base for mailbox-related exceptions. Extends `RuntimeException`. Catch to handle any mailbox failure.

### MailboxOverflowException

`Monadial\Nexus\Runtime\Exception\MailboxOverflowException`

Extends `MailboxException`.

**When thrown:** `OverflowStrategy::ThrowException` is configured and the mailbox is at capacity when `tell()` is called.

**Cause:** The producer is faster than the consumer. The mailbox is bounded and the exception strategy is set to `ThrowException`.

**Recovery:** Catch at the send site and apply backoff, or switch to `OverflowStrategy::Backpressure` to suspend the sender instead. The HTTP default mapper returns `503 Service Unavailable` when this exception escapes a handler.

**See also:** [Config — OverflowStrategy](./config.md#overflowstrategy)

### MailboxTimeoutException

`Monadial\Nexus\Runtime\Exception\MailboxTimeoutException`

Extends `MailboxException`.

**When thrown:** `Mailbox::dequeueBlocking(Duration)` returns after the timeout elapses with no message.

**Cause:** The actor's message loop waited for a message longer than the configured timeout. In normal operation, the runtime manages this internally.

**Recovery:** This exception is generally internal. If you see it in application code, you are calling `dequeueBlocking()` directly, which is a lower-level API. Let `ActorCell` manage the message loop.

---

## nexus-persistence

### ConcurrentModificationException

`Monadial\Nexus\Persistence\Exception\ConcurrentModificationException`

Extends `RuntimeException`.

**When thrown:** An optimistic-concurrency check fails in the event store: the expected version does not match the stored version.

**Cause:** Two actors (or two restarts of the same actor) attempted to write to the same persistence stream simultaneously, or a stale read occurred.

**Recovery:** Inspect `$e->persistenceId` and `$e->expectedVersion`. This exception signals a single-writer violation. Ensure only one actor instance writes to each `PersistenceId`. If using DBAL-backed stores, check database connection isolation.

**See also:** [Single-writer guarantee](../persistence/single-writer.md)

### RecoveryException

`Monadial\Nexus\Persistence\Exception\RecoveryException`

Extends `RuntimeException`.

**When thrown:** The persistence engine fails to replay events or load a snapshot during actor startup.

**Cause:** Corrupted event data, incompatible serialized format after a schema change, or a missing event handler for a replayed event type.

**Recovery:** Inspect `$e->persistenceId` and the wrapped `$previous` exception. Consider using `ReplayFilterMode::Warn` to skip interleaved writer events rather than failing. For schema changes, deploy an event-migration step before restarting actors.

**See also:** [Event sourcing](../persistence/event-sourcing.md)

### WriterConflictException

`Monadial\Nexus\Persistence\Exception\WriterConflictException`

Extends `RuntimeException`.

**When thrown:** The `ReplayFilter` detects events from a different `writerId` (ULID) than the current actor system's writer ID.

**Cause:** Two `ActorSystem` instances (separate processes or separate `ActorSystem::create()` calls) wrote to the same persistence stream. Violates the single-writer principle.

**Recovery:** Inspect `$e->persistenceId`, `$e->expectedWriter`, and `$e->actualWriter`. The `ReplayFilterMode` controls the response: `Fail` throws this exception, `Warn` logs it, `RepairByDiscardOld` silently drops the older writer's events.

**See also:** [Single-writer guarantee](../persistence/single-writer.md)

---

## nexus-serialization

### MessageDeserializationException

`Monadial\Nexus\Serialization\Exception\MessageDeserializationException`

Extends `SerializationException`.

**When thrown:** `MessageSerializer::deserialize()` fails to reconstruct a message object from its serialized form.

**Cause:** The serialized data is malformed, the target class no longer exists, or the class properties changed incompatibly after the data was written.

**Recovery:** Inspect `$e->typeName` and `$e->reason`. Implement event migrations or versioned message schemas before deploying breaking changes to message classes.

**See also:** [Attributes — #[MessageType]](./attributes.md#messagetype)

### MessageSerializationException

`Monadial\Nexus\Serialization\Exception\MessageSerializationException`

Extends `SerializationException`.

**When thrown:** `MessageSerializer::serialize()` fails to convert a message object to its wire format.

**Cause:** The message class contains a property type that the serializer cannot handle (e.g., a `Closure`, a `resource`, or a circular reference).

**Recovery:** Inspect `$e->messageClass` and `$e->reason`. Ensure all message properties are serializable scalars, arrays, or other `readonly class` objects. Add `#[MessageType]` and test serialization round-trips in your test suite.

### SerializationException

`Monadial\Nexus\Serialization\Exception\SerializationException`

Abstract base for all serialization failures. Extends `NexusException`. Catch to handle any serialization error.

---

## nexus-doctrine-dbal

### ConnectionPoisonedException

`Monadial\Nexus\Doctrine\Dbal\Exception\ConnectionPoisonedException`

Extends `NexusException`.

**When thrown:** A DBAL connection is used after it has been marked poisoned (e.g., a previous unrecoverable error put the connection in an invalid state).

**Cause:** A transaction rolled back in a way that left the connection unusable, or the database server dropped the connection mid-operation.

**Recovery:** Return the connection to the pool — the pool will discard poisoned connections and create fresh ones. Do not attempt to reuse a poisoned connection reference.

### MissingConnectionScopeException

`Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException`

Extends `NexusException`.

**When thrown:** A handler requests a `ConnectionLease` but `ConnectionScopeMiddleware` is not registered in the HTTP middleware pipeline.

**Cause:** `ConnectionScopeMiddleware` was not added to the application middleware chain before the route handler that needs a database connection.

**Recovery:** Add `ConnectionScopeMiddleware` globally: `$app->middleware(new ConnectionScopeMiddleware($pool))`.

### MissingTransactionalDependencyException

`Monadial\Nexus\Doctrine\Dbal\Exception\MissingTransactionalDependencyException`

Extends `NexusException`.

**When thrown:** A handler annotated with `#[Transactional]` does not declare a `Connection` (or `EntityManagerInterface`) parameter.

**Cause:** `#[Transactional]` requires the handler to accept a connection parameter so the framework can inject the scoped connection and wrap the handler in a transaction.

**Recovery:** Add a `Connection $connection` or `EntityManagerInterface $em` parameter to the handler's invocation method. See the error message for the exact handler class.

**See also:** [Attributes — #[Transactional]](./attributes.md#transactional)

### PoolClosedException

`Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException`

Extends `NexusException`.

**When thrown:** A connection is requested from a DBAL connection pool that has been shut down.

**Cause:** The pool was closed (e.g., during graceful shutdown) before all connection requests completed.

**Recovery:** Ensure connection acquisition happens before the pool is closed. During shutdown, drain pending requests before calling pool close.

### PoolExhaustedException

`Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException`

Extends `NexusException`.

**When thrown:** All connections in the pool are in use and no connection became available within the wait deadline.

**Cause:** Too many concurrent requests for the pool size, or long-running transactions holding connections.

**Recovery:** Inspect `$e->stats` (`inUse`, `total`, `waitingCoroutines`). Increase pool size via configuration, reduce transaction duration, or add a circuit breaker to shed load when the pool is exhausted.

---

## nexus-doctrine-orm

### EntityConflictException

`Monadial\Nexus\Doctrine\Orm\Exception\EntityConflictException`

Extends `NexusException`.

**When thrown:** Doctrine's optimistic locking fails — the entity's version field does not match the expected version at flush time.

**Cause:** Two concurrent requests updated the same entity between read and flush. The second flush triggers `OptimisticLockException` which is wrapped here.

**Recovery:** Catch `EntityConflictException`, reload the entity, re-apply the change, and retry. Inspect `$e->entityClass` and `$e->id` to identify the conflicting record.

### MissingEntityManagerScopeException

`Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException`

Extends `NexusException`.

**When thrown:** A handler requests an `EntityManagerLease` but `EntityManagerScopeMiddleware` is not registered.

**Cause:** `EntityManagerScopeMiddleware` was omitted from the middleware pipeline.

**Recovery:** Add `EntityManagerScopeMiddleware` to the application: `$app->middleware(new EntityManagerScopeMiddleware($emFactory))`.

---

## nexus-http-auth

### AuthException

`Monadial\Nexus\Http\Auth\Exception\AuthException`

Abstract base for all auth exceptions. Extends `NexusException`. The default `AuthorizationMiddleware` maps subclasses to HTTP responses: `Unauthenticated` → 401, `Forbidden` → 403.

### AuthMiddlewareNotRegisteredException

`Monadial\Nexus\Http\Auth\Exception\AuthMiddlewareNotRegisteredException`

Extends `AuthException`.

**When thrown:** A handler parameter uses `#[FromPrincipal]` but `AuthenticationMiddleware` was not registered in the pipeline — so no `Principal` was stamped on the request.

**Cause:** Missing `AuthenticationMiddleware` in the application middleware stack.

**Recovery:** Add `$app->middleware(new AuthenticationMiddleware($authenticator))` before any route that uses authentication.

**See also:** [Attributes — #[FromPrincipal]](./attributes.md#fromprincipal)

### Forbidden

`Monadial\Nexus\Http\Auth\Exception\Forbidden`

Extends `AuthException`. Mapped to HTTP 403 by `AuthorizationMiddleware`.

**When thrown:** `AuthorizationMiddleware` evaluates the principal's roles/scopes against the handler's `#[RequiresRole]`, `#[RequiresScope]`, or `#[Authorize]` attributes and finds a mismatch.

**Payload:** `$missing` — a list of the failed constraints. Never contains principal claims.

**Recovery:** Ensure the caller has the required roles/scopes before sending the request. On the server side, verify the `#[RequiresRole]`/`#[RequiresScope]` annotations are correct.

### InvalidAuthorizerException

`Monadial\Nexus\Http\Auth\Exception\InvalidAuthorizerException`

Extends `AuthException`.

**When thrown:** At application boot time, `#[Authorize(SomeClass::class)]` is evaluated and `SomeClass` does not implement the `Authorizer` interface.

**Cause:** Programmer error — the wrong class was passed to `#[Authorize]`.

**Recovery:** Implement `Authorizer` on the referenced class.

### Unauthenticated

`Monadial\Nexus\Http\Auth\Exception\Unauthenticated`

Extends `AuthException`. Mapped to HTTP 401 by `AuthorizationMiddleware`.

**When thrown:** `AuthorizationMiddleware` finds no valid principal on the request (credentials absent or invalid).

**Recovery:** Present valid credentials. Customize the 401 response body via `$app->onException(Unauthenticated::class, fn($e) => ...)`.

---

## nexus-http

### DuplicateActorNameException

`Monadial\Nexus\Http\Exception\DuplicateActorNameException`

Extends `NexusException`.

**When thrown:** `HttpApp::actor()` is called twice with the same actor name at boot time.

**Cause:** Duplicate registration — two calls to `->actor('name', ...)` with the same string.

**Recovery:** Use unique names per actor registration. The second call triggers this exception.

### DuplicateRouteNameException

`Monadial\Nexus\Http\Exception\DuplicateRouteNameException`

Extends `NexusException`.

**When thrown:** Two routes are registered with the same name.

**Cause:** `->route(...)->named('foo')` is called for two different routes with `'foo'`.

**Recovery:** Ensure route names are unique across the application. Names are used for URL generation.

### GenericHttpException

`Monadial\Nexus\Http\Exception\GenericHttpException`

Extends `HttpException`. Concrete carrier used by `HttpException` factory methods (`notFound()`, `forbidden()`, etc.). Not typically thrown directly — use the factory methods on `HttpException`.

### HttpException

`Monadial\Nexus\Http\Exception\HttpException`

Abstract base for HTTP-aware exceptions. Extends `NexusException`. Carries `$status` (HTTP status code) and `$headers`. The default exception mapper converts it directly to an HTTP response using the status code.

**Factory methods:** `notFound()` → 404, `unauthorized()` → 401, `forbidden()` → 403, `unprocessableEntity(array $errors)` → 422, `conflict()` → 409.

**Recovery:** Throw `HttpException::notFound($message)` from a handler to return a 404. Register a custom mapper via `$app->onException(HttpException::class, ...)` to override the default body format.

### MethodNotAllowedException

`Monadial\Nexus\Http\Exception\MethodNotAllowedException`

Extends `HttpException`. HTTP 405 with `Allow` header.

**When thrown:** A request matches a registered path but the HTTP method is not allowed for that path.

**Recovery:** Check `$e->allowed` for the permitted methods. Return the 405 response with the `Allow` header (set automatically). Verify the client is using the correct HTTP method.

### PerRequestActorInConstructorException

`Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException`

Extends `NexusException`.

**When thrown:** A constructor parameter uses `#[FromActor]` to request a per-request actor. Per-request actors are only available inside the handler invocation method.

**Cause:** Per-request actors are spawned per-request, so they cannot exist at construction time.

**Recovery:** Move the `#[FromActor]` parameter from `__construct` to the handler's `__invoke` method.

### PerRequestScopeDisposedException

`Monadial\Nexus\Http\Exception\PerRequestScopeDisposedException`

Extends `NexusException`.

**When thrown:** A per-request actor spawn is attempted after the request scope has been disposed (after the response is sent).

**Cause:** Spawning an actor inside an async callback that fires after response dispatch.

**Recovery:** Spawn all per-request actors before the response is returned. Do not spawn in after-response hooks.

### PoolSingletonRequiresSpawnerException

`Monadial\Nexus\Http\Exception\PoolSingletonRequiresSpawnerException`

Extends `NexusException`.

**When thrown:** One or more actors are configured as `poolSingleton()` but no `PoolSingletonSpawner` is attached to `HttpApp`.

**Cause:** `poolSingleton()` actors require a server package that wires a spawner (e.g., the Swoole server integration). Missing integration step.

**Recovery:** Wire a `PoolSingletonSpawner` via `$app->withSpawner(...)`, or change the actor mode to `workerLocal()`.

### RouteNotFoundException

`Monadial\Nexus\Http\Exception\RouteNotFoundException`

Extends `HttpException`. HTTP 404.

**When thrown:** No registered route matches the incoming request's method and path.

**Recovery:** Verify the route is registered. Check path prefix configuration if routes are mounted under a prefix. The default mapper returns a 404 response automatically.

### UnknownActorException

`Monadial\Nexus\Http\Exception\UnknownActorException`

Extends `NexusException`.

**When thrown:** A handler or scope requests an actor by name that was not registered with `HttpApp::actor()`.

**Cause:** Typo in the actor name string, or the actor registration was removed while the handler still references it.

**Recovery:** Verify the actor name string matches exactly what was passed to `->actor('name', ...)`. Actor names are case-sensitive.

### UnresolvableParameterException

`Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException`

Extends `LogicException`.

**When thrown:** At compile/boot time, a handler parameter cannot be resolved by any registered `ParamResolver`.

**Cause:** The parameter has no recognized attribute (`#[FromBody]`, `#[FromActor]`, `#[FromService]`, `#[FromPrincipal]`), is not a path parameter, and is not type-hinted to a known injectable type.

**Recovery:** Add the appropriate attribute to the parameter. The exception message lists all valid resolution strategies. Register a custom `ParamResolver` via `$app->paramResolver(...)` for domain-specific injection.

---

## nexus-messenger

### UnsupportedOperationException

`Monadial\Nexus\Messenger\Exception\UnsupportedOperationException`

Extends `NexusException`.

**When thrown:** `MessengerActorRef::ask()` is called. Broker request/reply requires correlation stamps and a dedicated reply transport; this is deferred beyond v1.

**Cause:** Actor code called `ask()` on a `MessengerActorRef`. The `ActorRef` interface requires `ask()` but the Messenger transport layer cannot fulfil a synchronous reply in v1.

**Recovery:** Use `tell()` instead and model the reply as a separate inbound message routed back through a `ReceiverActor`. If you need request/reply semantics across a broker, implement a correlation-stamp pattern manually.

**See also:** [MessengerActorRef](./classes/messenger-actor-ref.md) — the ref class that throws this exception

---

## nexus-http-ws

### DuplicateRouteException

`Monadial\Nexus\Http\Ws\WebSocket\Exception\DuplicateRouteException`

Extends `RuntimeException`.

**When thrown:** Two WebSocket routes are registered with the same path pattern.

**Recovery:** Ensure each WebSocket path is unique in the WebSocket route registry.

### UnsupportedRouteException

`Monadial\Nexus\Http\Ws\WebSocket\Exception\UnsupportedRouteException`

Extends `RuntimeException`.

**When thrown:** A WebSocket upgrade request arrives for a path that has no registered WebSocket handler.

**Recovery:** Register a handler for the path before the server starts. If the path is intentionally absent, return an HTTP 404 before the WebSocket upgrade completes.

---

## Untyped throws

Beyond the 42 typed exception classes above, the Nexus codebase contains approximately 128 sites that throw standard PHP exceptions — `RuntimeException`, `LogicException`, and `InvalidArgumentException` — directly without a Nexus-specific subclass. These are concentrated in:

- Internal runtime plumbing (fiber scheduling, Swoole coroutine management)
- Validation guards on named constructors (e.g., `WorkerPoolConfig::withThreads()` throws `InvalidArgumentException` for `$workerCount < 1`)
- Third-party integration adapters (Doctrine DBAL driver errors not yet wrapped)

These untyped throws are not part of the public API contract. They may be wrapped in typed `NexusException` subclasses in future versions. When catching exceptions from Nexus code broadly, catch `NexusException` for the typed surface and `\Throwable` as a fallback.

---

## See also

- [Configuration reference](./config.md) — `MailboxConfig`, `OverflowStrategy`, `SupervisionStrategy`
- [Lifecycle signals](./lifecycle-signals.md) — `ChildFailed`, `PostStop`, `Terminated`
- [Gotchas](./gotchas.md) — common traps related to exception handling
- [Supervision](../core-concepts/supervision.md) — how exceptions propagate and are handled
