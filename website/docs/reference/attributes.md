---
title: Attribute index
related:
  - reference/psalm-rules
  - reference/exceptions
  - http/handlers
  - core-concepts/actors
---

# Attribute index

This page catalogs every PHP 8 attribute (`#[...]`) defined across Nexus packages. Attributes are metadata-only at runtime; the Psalm plugin and the HTTP framework read them at static analysis time and request-dispatch time respectively.

---

## nexus-core

### #[ReplyType]

`Monadial\Nexus\Core\Actor\Attribute\ReplyType`

**Target:** `Attribute::TARGET_CLASS` — place on a message class.

**Repeatable:** No.

**Constructor args:**

| Parameter | Type | Description |
|---|---|---|
| `$replyClass` | `class-string` | The class the actor sends back via `$ctx->reply(...)` when handling this message. |

The `AskReturnTypeProvider` Psalm plugin reads `#[ReplyType]` at the `ask()` call site and narrows the inferred return type of the future from `Future<mixed>` to `Future<DeclaredReplyClass>`.

```php title="src/Message/GetOrder.php"
use Monadial\Nexus\Core\Actor\Attribute\ReplyType;

#[ReplyType(Order::class)]
final readonly class GetOrder
{
    public function __construct(public readonly string $orderId) {}
}
```

---

## nexus-serialization

### #[MessageType]

`Monadial\Nexus\Serialization\MessageType`

**Target:** `Attribute::TARGET_CLASS` — place on a message class.

**Repeatable:** No.

**Constructor args:**

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | Stable wire-format type identifier. Must not change once data has been persisted or sent. |

`TypeRegistry` uses `#[MessageType]` to map between PHP class names and their serialized type identifier. Required for all messages sent via `WorkerActorRef` (cross-thread) or serialized to the event store.

```php title="src/Message/OrderPlaced.php"
use Monadial\Nexus\Serialization\MessageType;

#[MessageType('orders.OrderPlaced')]
final readonly class OrderPlaced
{
    public function __construct(
        public readonly string $orderId,
        public readonly int $amount,
    ) {}
}
```

:::caution Change $name = data loss
Changing `#[MessageType]`'s `$name` after data has been persisted means the deserializer can no longer find the class for existing records. Treat the name as an immutable wire contract.
:::

---

## nexus-http

### #[FromActor]

`Monadial\Nexus\Http\Handler\Attribute\FromActor`

**Target:** `Attribute::TARGET_PARAMETER` — place on a handler or constructor parameter.

**Repeatable:** No.

**Constructor args:**

| Parameter | Type | Description |
|---|---|---|
| `$name` | `string` | The actor name registered with `HttpApp::actor()`. |

Injects an `ActorRef` registered with `HttpApp`. Constructor injection allows `poolSingleton` and `workerLocal` actors only. Method injection allows all modes including `perRequest`.

```php title="src/Handler/OrderHandler.php"
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Core\Actor\ActorRef;

final class OrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}
}
```

### #[FromBody]

`Monadial\Nexus\Http\Handler\Attribute\FromBody`

**Target:** `Attribute::TARGET_PARAMETER`.

**Repeatable:** No.

**Constructor args:** None.

Deserializes the HTTP request body into the parameter's declared type using the `MessageSerializer` configured via `HttpApp::withMessageSerializer()`. Fails at boot time if no serializer is configured.

```php title="src/Handler/CreateOrderHandler.php"
use Monadial\Nexus\Http\Handler\Attribute\FromBody;

final class CreateOrderHandler
{
    public function __invoke(#[FromBody] CreateOrderRequest $request): void
    {
        // $request is deserialized from JSON body
    }
}
```

### #[FromService]

`Monadial\Nexus\Http\Handler\Attribute\FromService`

**Target:** `Attribute::TARGET_PARAMETER`.

**Repeatable:** No.

**Constructor args:**

| Parameter | Type | Default | Description |
|---|---|---|---|
| `$id` | `string\|null` | `null` | PSR-11 container ID. When `null`, resolves by the parameter's type hint. |

Injects a service from the PSR-11 container. Requires a `ContainerInterface` passed to `HttpApp::create()`.

```php title="src/Handler/AuditHandler.php"
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Psr\Log\LoggerInterface;

final class AuditHandler
{
    public function __construct(
        #[FromService('logger.audit')] private readonly LoggerInterface $logger,
    ) {}
}
```

---

## nexus-http-auth

### #[Authorize]

`Monadial\Nexus\Http\Auth\Attribute\Authorize`

**Target:** `Attribute::TARGET_CLASS` — place on a handler class.

**Repeatable:** No.

**Constructor args:**

| Parameter | Type | Description |
|---|---|---|
| `$authorizer` | `class-string<Authorizer>` | Class implementing `Authorizer`. Validated at boot — throws `InvalidAuthorizerException` if not an `Authorizer`. |

Delegates authorization to a custom policy. `AuthorizationMiddleware` resolves the authorizer (via PSR-11 container or no-args construction) and calls `authorize(Principal, ServerRequest)`. Returns 403 if it returns `false`.

```php title="src/Handler/ShowOrderHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\Authorize;
use App\Policy\OrderOwnerPolicy;

#[Authorize(OrderOwnerPolicy::class)]
final class ShowOrderHandler { /* ... */ }
```

### #[FromPrincipal]

`Monadial\Nexus\Http\Auth\Attribute\FromPrincipal`

**Target:** `Attribute::TARGET_PARAMETER` — place on a constructor or method parameter.

**Repeatable:** No.

**Constructor args:** None.

Injects the `Principal` stamped by `AuthenticationMiddleware` onto the request. Throws `AuthMiddlewareNotRegisteredException` (HTTP 500) if `AuthenticationMiddleware` is absent.

```php title="src/Handler/ProfileHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;

final class ProfileHandler
{
    public function __construct(
        #[FromPrincipal] private readonly Principal $principal,
    ) {}
}
```

### #[RequiresAuth]

`Monadial\Nexus\Http\Auth\Attribute\RequiresAuth`

**Target:** `Attribute::TARGET_CLASS`.

**Repeatable:** No.

**Constructor args:** None.

Requires a valid authenticated principal. `AuthorizationMiddleware` returns 401 if no principal is present. All sibling attributes (`#[RequiresRole]`, `#[RequiresScope]`, etc.) imply `#[RequiresAuth]` — no need to combine them.

```php title="src/Handler/DashboardHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;

#[RequiresAuth]
final class DashboardHandler { /* ... */ }
```

### #[RequiresRole]

`Monadial\Nexus\Http\Auth\Attribute\RequiresRole`

**Target:** `Attribute::TARGET_CLASS`.

**Repeatable:** No.

**Constructor args:** `string ...$roles` — variadic list of required roles (all-of check: principal must have every listed role).

```php title="src/Handler/AdminHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\RequiresRole;

#[RequiresRole('admin', 'superuser')]
final class AdminHandler { /* ... */ }
```

### #[RequiresAnyRole]

`Monadial\Nexus\Http\Auth\Attribute\RequiresAnyRole`

**Target:** `Attribute::TARGET_CLASS`.

**Repeatable:** No.

**Constructor args:** `string ...$roles` — variadic list; principal must have at least one.

```php title="src/Handler/ModeratorHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyRole;

#[RequiresAnyRole('admin', 'moderator')]
final class ModeratorHandler { /* ... */ }
```

### #[RequiresScope]

`Monadial\Nexus\Http\Auth\Attribute\RequiresScope`

**Target:** `Attribute::TARGET_CLASS`.

**Repeatable:** No.

**Constructor args:** `string ...$scopes` — all-of check: principal must have every listed scope.

```php title="src/Handler/WriteOrderHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;

#[RequiresScope('orders.read', 'orders.write')]
final class WriteOrderHandler { /* ... */ }
```

### #[RequiresAnyScope]

`Monadial\Nexus\Http\Auth\Attribute\RequiresAnyScope`

**Target:** `Attribute::TARGET_CLASS`.

**Repeatable:** No.

**Constructor args:** `string ...$scopes` — any-of check: principal must have at least one.

```php title="src/Handler/ReadHandler.php"
use Monadial\Nexus\Http\Auth\Attribute\RequiresAnyScope;

#[RequiresAnyScope('orders.read', 'orders.admin')]
final class ReadHandler { /* ... */ }
```

---

## nexus-doctrine-dbal

### #[Transactional]

`Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional`

**Target:** `Attribute::TARGET_CLASS | Attribute::TARGET_METHOD`.

**Repeatable:** No.

**Constructor args:** None.

Wraps the annotated handler or method in a DBAL transaction. The framework begins the transaction before invocation and commits on success; rolls back on exception. Requires the handler to declare a `Connection` (or `EntityManagerInterface`) parameter — throws `MissingTransactionalDependencyException` otherwise.

```php title="src/Handler/TransferHandler.php"
use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Http\Attribute\Transactional;

#[Transactional]
final class TransferHandler
{
    public function __invoke(Connection $connection, TransferRequest $req): void
    {
        // runs inside a transaction; auto-committed on return
    }
}
```

---

## nexus-http-ws

### #[FromContext] (WebSocket variant)

`Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext`

**Target:** `Attribute::TARGET_PARAMETER`.

**Repeatable:** No.

**Constructor args:** None.

Injects the WebSocket connection context object into a WebSocket handler parameter. Specific to `nexus-http-ws`; distinct from any HTTP-side `FromContext` that might exist in application code.

---

## See also

- [Psalm rules](./psalm-rules.md) — Psalm plugin hooks that enforce attribute usage at static analysis time
- [HTTP handlers](../http/handlers.md) — how `#[FromBody]`, `#[FromActor]`, `#[FromService]` resolve at dispatch
- [Exceptions — AuthMiddlewareNotRegisteredException](./exceptions.md#authmiddlewarenotregisteredexception) — what happens when `#[FromPrincipal]` has no middleware
- [Exceptions — UnresolvableParameterException](./exceptions.md#unresolvableparameterexception) — what happens when no attribute resolves a parameter
