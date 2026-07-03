---
title: Psalm rules
related:
  - reference/attributes
  - reference/gotchas
  - core-concepts/actors
  - reference/exceptions
---

# Psalm rules

The `nexus-psalm` package ships a Psalm plugin with rules and return-type providers that enforce Nexus-specific correctness at static analysis time. Register it in `psalm.xml`:

```xml title="psalm.xml"
<plugins>
    <pluginClass class="Monadial\Nexus\Psalm\NexusPlugin" />
</plugins>
```

All rules run at Psalm level 1 (the strictest level). Psalm reports violations as custom issue types; suppress with `@psalm-suppress IssueTypeName` on the specific line or method docblock.

---

## ReadonlyMessageRule

**Hook class:** `Monadial\Nexus\Psalm\Hook\ReadonlyMessageRule`

**What it catches:** Messages passed to `ActorRef::tell()`, `ActorContext::scheduleOnce()`, and `ActorContext::scheduleRepeatedly()` that are not declared `readonly class`.

**Issue type:** `NonReadonlyMessage`

**Example error:**

```
ERROR: NonReadonlyMessage: Message class OrderCreated passed to tell() is not readonly.
       Mutable messages are unsafe in concurrent actor systems.
```

**Why:** Actors run on separate fibers or threads. A mutable message object shared between actors can be mutated by one actor while another is reading it, producing data races. `readonly class` enforces copy-on-write semantics and communicates immutability intent.

**Fix:**

:::caution Don't do this
```php title="src/Message/BadOrder.php" verify:lint-only
class OrderCreated  // missing readonly — Psalm will flag this
{
    public string $orderId;
}
```
:::

```php title="src/Message/OrderCreated.php"
final readonly class OrderCreated
{
    public function __construct(public string $orderId) {}
}
```

---

## MutableActorStateRule

**Hook class:** `Monadial\Nexus\Psalm\Hook\MutableActorStateRule`

**What it catches:** Public non-`readonly` properties on classes that implement `ActorHandler` or `StatefulActorHandler`.

**Issue type:** `MutableActorState`

**Example error:**

```
ERROR: MutableActorState: Property CounterActor::$count is public and mutable.
       Actor handler properties must be readonly to prevent shared-state bugs.
```

**Why:** Class-based actors are instantiated once and handle many messages sequentially. A public mutable property creates a shared state surface accessible from outside the actor. All per-actor state should be encapsulated via the handler's private/readonly properties or via `StatefulActorHandler::initialState()`.

**Fix:**

:::caution Don't do this
```php title="src/Actor/BadActor.php" verify:lint-only
class CounterActor implements ActorHandler
{
    public int $count = 0;  // public + mutable — flagged
}
```
:::

```php title="src/Actor/CounterActor.php"
use Monadial\Nexus\Core\Actor\StatefulActorHandler;

final class CounterActor implements StatefulActorHandler
{
    public function initialState(): int
    {
        return 0;
    }
    // state flows through StatefulActorHandler::handle(), not properties
}
```

---

## NonSerializableRemoteMessageRule

**Hook class:** `Monadial\Nexus\Psalm\Hook\NonSerializableRemoteMessageRule`

**What it catches:** Messages passed to `WorkerActorRef::tell()` (cross-worker tells) that do not have a `#[MessageType]` attribute.

**Issue type:** `NonSerializableRemoteMessage`

**Example error:**

```
ERROR: NonSerializableRemoteMessage: Message class GetOrder passed to WorkerActorRef::tell()
       has no #[MessageType] attribute. Cross-worker messages require a stable type name.
```

**Why:** `WorkerActorRef::tell()` routes messages across Swoole thread boundaries. The serializer uses `#[MessageType]` as the stable wire-format key. Without it, the message cannot be deserialized on the receiving worker.

**Fix:**

```php title="src/Message/GetOrder.php"
use Monadial\Nexus\Serialization\MessageType;

#[MessageType('orders.GetOrder')]
final readonly class GetOrder
{
    public function __construct(public readonly string $orderId) {}
}
```

**See also:** [Attributes — #[MessageType]](./attributes.md#messagetype)

---

## BlockingCallInHandlerRule

**Hook class:** `Monadial\Nexus\Psalm\Hook\BlockingCallInHandlerRule`

**What it catches:** Calls to blocking PHP functions inside classes that implement `ActorHandler` or `StatefulActorHandler`.

**Issue type:** `BlockingCallInHandler`

**Detected functions:** `sleep`, `usleep`, `time_nanosleep`, `time_sleep_until`, `file_get_contents`, `file_put_contents`, `fread`, `fwrite`, `fgets`, `fopen`, `curl_exec`, `proc_open`, `shell_exec`, `exec`, `system`, `passthru`, `popen`.

**Example error:**

```
ERROR: BlockingCallInHandler: Blocking call 'file_get_contents' inside ActorHandler.
       Blocking calls stall the fiber scheduler and starve all other actors.
```

**Why:** Actor handlers run on PHP fibers. A blocking call (`sleep`, `curl_exec`, etc.) parks the OS thread rather than suspending the fiber, starving all other actors running on the same thread. Use async-capable alternatives: `$ctx->scheduleOnce()` for delays, Swoole coroutine HTTP clients for I/O.

**Fix:**

:::caution Don't do this
```php title="src/Actor/FetchActor.php" verify:lint-only
public function handle(ActorContext $ctx, object $msg): Behavior
{
    $data = file_get_contents('https://api.example.com/data');  // blocks fiber
    return Behavior::same();
}
```
:::

```php title="src/Actor/FetchActor.php"
// Use Swoole coroutine client inside SwooleRuntime, or send the result
// via a dedicated IO actor that uses non-blocking APIs
public function handle(ActorContext $ctx, object $msg): Behavior
{
    // delegate to a separate I/O actor or use runtime::scheduleOnce
    return Behavior::same();
}
```

---

## MutableClosureCaptureRule

**Hook class:** `Monadial\Nexus\Psalm\Hook\MutableClosureCaptureRule`

**What it catches:** Closures passed to `Props::fromFactory()`, `Props::fromStatefulFactory()`, or `Props::fromContainer()` that capture variables by reference (`use (&$var)`).

**Issue type:** `MutableClosureCapture`

**Example error:**

```
ERROR: MutableClosureCapture: Variable $counter captured by reference in Props::fromFactory() closure.
       Factory closures must not capture by reference — each worker gets its own instance.
```

**Why:** `Props::fromFactory()` closures are called once per actor spawn (and once per worker thread in a worker pool). By-reference captures create a shared mutable variable across spawns, breaking actor isolation.

**Fix:**

:::caution Don't do this
```php title="src/Actor/BadFactory.php" verify:lint-only
$counter = 0;
Props::fromFactory(function () use (&$counter) {  // by-ref capture — flagged
    $counter++;
    return new CounterActor();
});
```
:::

```php title="src/Actor/GoodFactory.php"
Props::fromFactory(static fn() => new CounterActor());
// State lives inside CounterActor, not in the closure
```

---

## UntypedActorRefInjectionRule

**Hook classes:** `Monadial\Nexus\Psalm\Hook\UntypedActorRefInjectionRule`, `Monadial\Nexus\Psalm\Hook\UntypedActorRefPropertyRule`

**What it catches:** Injected `ActorRef` parameters and properties (constructor, promoted, method, closure) whose declared type does not name a concrete message type. Bare `ActorRef`, explicit `ActorRef<object>`, concrete subtypes (`LocalActorRef`, `MessengerActorRef`, …) without a generic, and refs hidden inside containers (`array<string, ActorRef>`) are all flagged.

**Issue type:** `UntypedActorRefInjection`

**Example error:**

```
ERROR: UntypedActorRefInjection: ActorRef injection for parameter $orders of App\OrderController::__construct
       must declare a concrete message type, e.g. ActorRef<MyCommand>.
       Bare ActorRef and ActorRef<object> defeat typed messaging at the injection boundary.
```

**Why:** `ActorRef<T>` is only type-safe when `T` is declared. A bare `ActorRef` injected into a controller or service accepts *any* object in `tell()` — the type safety the actor system is built around silently disappears at every DI boundary.

**Fix:**

:::caution Don't do this
```php title="src/Http/CreateOrderHandler.php" verify:lint-only
final class CreateOrderHandler
{
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,  // Psalm will flag this
    ) {}
}
```
:::

```php title="src/Http/CreateOrderHandler.php"
final class CreateOrderHandler
{
    /** @param ActorRef<OrderCommand> $orders */
    public function __construct(
        #[FromActor('orders')] private readonly ActorRef $orders,
    ) {}
}
```

**Excluding accept-anything refs:** `DeadLetterRef` is excluded by default. Exclude your own heterogeneous ref classes via plugin config:

```xml title="psalm.xml"
<plugins>
    <pluginClass class="Monadial\Nexus\Psalm\Plugin">
        <untypedActorRefInjection>
            <excludeRef class="App\Infra\AuditSinkRef"/>
        </untypedActorRefInjection>
    </pluginClass>
</plugins>
```

**Exempting by-design heterogeneous positions:** where a *consumer* legitimately holds refs of mixed message types (registry maps, dead-letter sinks), scope the rule out per file with Psalm's standard mechanism instead of inline suppressions:

```xml title="psalm.xml"
<issueHandlers>
    <PluginIssue name="UntypedActorRefInjection">
        <errorLevel type="suppress">
            <!-- registry map: one map holds refs with different message types -->
            <file name="src/Infra/ActorRegistry.php"/>
        </errorLevel>
    </PluginIssue>
</issueHandlers>
```

---

## PropsReturnTypeProvider

**Hook class:** `Monadial\Nexus\Psalm\Hook\PropsReturnTypeProvider`

**What it does:** A return-type provider (not a rule) that infers the generic type parameter for `Props::fromBehavior()`, `Props::fromFactory()`, and related named constructors. Psalm cannot infer `Props<T>` from the closure's message type without help.

**Example:** Without this provider, `Props::fromBehavior($behavior)` returns `Props<object>`. With it, Psalm infers `Props<MyMessage>` from the behavior's handler signature.

**No suppressible error.** This provider adds type information rather than reporting issues. If `Props<T>` appears as `Props<object>` in your Psalm output, check that the plugin is registered.

---

## CloneWithReturnTypeProvider

**Hook class:** `Monadial\Nexus\Psalm\Hook\CloneWithReturnTypeProvider`

**What it does:** A return-type provider for PHP 8.4's `clone()` expression with named arguments (e.g., `clone($this, ['capacity' => $n])`). Without this provider, Psalm infers the return type as `mixed`. With it, Psalm preserves the object's original type through the clone operation.

**No suppressible error.** This provider is purely additive. If you see `mixed` instead of the expected class type after a `clone(...)` call, verify the plugin is registered and Psalm is at least version 5.x.

---

## See also

- [Attributes](./attributes.md) — the `#[MessageType]`, `#[ReplyType]` attributes that several rules reference
- [Gotchas — factory closure capture rules](./gotchas.md#factory-closures-must-not-capture-by-reference)
- [Gotchas — #[MessageType] required for cross-worker](./gotchas.md#messagetype-is-required-for-cross-worker-tells)
- [Core concepts — actors](../core-concepts/actors.md) — `ActorHandler` and `StatefulActorHandler` interfaces
