---
title: Troubleshooting
related:
  - operations/runbook
  - reference/exceptions
  - reference/psalm-rules
---

# Troubleshooting

Common symptoms, their root causes, and fixes. Each entry follows the pattern: what you observe → why it happens → what to do.

---

### 1. My actor never receives messages

**Symptom:** You call `$ref->tell(new MyMessage())` and nothing happens. No log output, no handler execution.

**Cause:** The most common cause is that `$system->run()` has not been called, so the runtime event loop is not running and no fiber is processing messages. A second cause is that the actor was spawned with an unbounded mailbox and `run()` is called only after all messages are enqueued — this is fine but messages process asynchronously; adding `scheduleOnce` to check results after a delay is required in tests.

**Fix:** Ensure `$system->run()` is the last call in your entry point. In tests, use the Step runtime and call `$runtime->drain()` explicitly, or use `scheduleOnce` with a short delay before reading results:

```php title="tests/MyActorTest.php"
$runtime = new FiberRuntime();
$system  = ActorSystem::create('test', $runtime);
$ref     = $system->spawn(Props::fromBehavior($behavior), 'worker');
$ref->tell(new MyMessage());

$runtime->scheduleOnce(
    Duration::millis(100),
    fn() => $system->shutdown(Duration::seconds(1)),
);
$system->run();
```

**See also:** [Quick start](../getting-started/quick-start.md)

---

### 2. `ActorNameExistsException` on respawn

**Symptom:** `ActorNameExistsException: Actor 'orders' already exists under /user` when you try to spawn a second time with the same name.

**Cause:** `ActorSystem::spawn()` throws this exception when an actor with the given name is still alive. A stopped actor does not block respawn — only a live one does.

**Fix:** Either wait for the previous actor to stop before spawning again, or use a unique name per entity (e.g. include the entity ID). For entity actors that respawn on demand, check `isAlive()` first:

```php title="src/Actors/EntityRegistry.php"
if (!isset($this->refs[$id]) || !$this->refs[$id]->isAlive()) {
    $this->refs[$id] = $system->spawn(
        Props::fromBehavior($behavior),
        'order-' . $id,
    );
}
```

**See also:** [Reference overview](../reference/overview.md)

---

### 3. Psalm template parameter complaints

**Symptom:** Psalm reports `InvalidTemplateParam` or `MismatchingDocblockParamType` on `Props::fromBehavior()` or `ActorRef::tell()`.

**Cause:** Nexus uses Psalm generics (`Props<T>`, `ActorRef<T>`, `Behavior<T>`) to enforce type safety at compile time. Psalm cannot infer `T` when the behavior closure uses `object` as the message type rather than a concrete class.

**Fix:** Narrow the closure parameter type to the specific message class, or add an explicit `@template-actual-type` annotation. The Nexus Psalm plugin infers `T` from the first parameter of the `receive()` closure:

```php title="src/Actors/CounterActor.php"
// Wrong — Psalm infers T = object
$behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same());

// Correct — Psalm infers T = Increment
$behavior = Behavior::receive(
    static fn(ActorContext $ctx, Increment $msg) => Behavior::same(),
);
```

**See also:** [Reference overview](../reference/overview.md)

---

### 4. `Channel is closed` (Swoole)

**Symptom:** `Swoole\Exception: Channel is closed` thrown during message processing or `ask()`.

**Cause:** This is the Swoole equivalent of `MailboxClosedException`. It fires when a coroutine attempts to read from or write to a channel that has already been closed — typically because the actor's mailbox was closed during shutdown while a coroutine was still waiting on it.

**Fix:** In most cases this is benign: it happens during normal shutdown and is caught by the runtime's message loop. If you see it outside of shutdown, check that you are not calling `tell()` on a stopped actor reference. Use `$ref->isAlive()` before sending:

```php title="src/Actors/ProducerActor.php"
if ($workerRef->isAlive()) {
    $workerRef->tell($task);
} else {
    $ctx->log()->warning('Worker stopped; dropping task', ['task' => $task]);
}
```

---

### 5. Stale EntityManager reads (Doctrine)

**Symptom:** An entity fetched inside an actor handler returns old data despite a recent database write by another process.

**Cause:** Doctrine's first-level cache (identity map) is per-`EntityManager` instance. In a Swoole coroutine context, a single `EntityManager` is scoped to the HTTP request via the connection scope middleware. If your actor fetches an entity outside of a request scope — or reuses the same `EntityManager` across requests — the identity map is never cleared.

**Fix:** Always fetch entities inside the connection scope. If you need fresh data, call `$em->clear()` before the query or `$em->refresh($entity)` after:

```php title="src/Actors/ReadModelActor.php"
// Inside a handler that runs within an EntityManager scope:
$em->clear(); // discard identity map
$order = $em->find(Order::class, $this->orderId);
```

**See also:** [Doctrine ORM integration](../doctrine/overview.md)

---

### 6. Mailbox overflow in production

**Symptom:** `MailboxOverflowException` in logs, or messages silently dropped (if using `DropNewest`/`DropOldest` strategy).

**Cause:** The actor is receiving messages faster than it can process them and the bounded mailbox has reached capacity.

**Fix:** Check the overflow strategy configured on `MailboxConfig`. The default for bounded mailboxes is `DropNewest`. Switch to `Backpressure` to apply back-pressure to senders, or increase the capacity. Long-term: profile the handler to find the bottleneck, or use a worker pool to fan out across threads.

```php title="src/Config/MailboxSetup.php"
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(10_000, OverflowStrategy::Backpressure));
```

**See also:** [Choosing thread count](../scaling/choosing-thread-count.md)

---

### 7. `PoisonPill` doesn't stop the actor

**Symptom:** You send `PoisonPill` (or call `$system->stop($ref)`) but `$ref->isAlive()` remains `true`.

**Cause:** `PoisonPill` is processed in-order with user messages. If the actor has a large backlog, or if a handler is blocking (e.g. synchronous sleep or file I/O), the pill sits in the queue until all prior messages are processed.

**Fix:** For testing, wait longer or drain the runtime explicitly. For production, investigate why the actor's backlog is growing. If a handler is blocking, fix the blocking call. If you need immediate termination, call `$system->shutdown(Duration::millis(500))` which force-stops all actors after the timeout:

```bash title="terminal"
# In a test: use StepRuntime and drain before checking isAlive
docker compose exec php vendor/bin/phpunit --filter=testActorStopsOnPoisonPill
```

---

### 8. `AskTimeoutException` despite reply being sent

**Symptom:** The caller receives `AskTimeoutException` but the handler log shows the reply was sent.

**Cause:** The reply arrived after the ask timeout expired. `ask()` creates a temporary one-shot actor with a `ReceiveTimeout`; if the reply is in flight when the timeout fires, the one-shot actor is already stopped and the reply goes to dead letters.

**Fix:** Increase the ask timeout. If the handler is consistently slow, either move the work to a background actor and use `tell`-based fire-and-forget, or increase the timeout to match the 99th-percentile handler duration:

```php title="src/Client/OrderClient.php"
// Increase from 1 s to 5 s
$result = $ref->ask(
    /** @param ActorRef<OrderFound> $replyTo */
    static fn(ActorRef $replyTo) => new GetOrder($id, $replyTo),
    Duration::seconds(5),
);
```

**See also:** [Ask pattern](../core-concepts/ask-pattern.md), [Reference overview](../reference/overview.md)

---

### 9. `InvalidActorPathException` on entity IDs

**Symptom:** `InvalidActorPathException: Invalid actor path: '/user/order-abc/123'` when spawning an actor whose name is derived from an entity ID.

**Cause:** Actor names must match `[a-zA-Z0-9_\-\.]+`. Slashes, spaces, and other special characters are forbidden because the name is embedded in the actor path.

**Fix:** Sanitize the entity ID before using it as an actor name. Replace slashes with dashes or encode the value:

```php title="src/Actors/EntityRefFactory.php"
// Wrong: UUID with hyphens is fine; UUID with slash is not.
$name = 'order-' . $entityId; // OK if entityId has no slash

// Wrong: URL path used as ID
$name = 'order-' . $urlPath; // '/cart/123' breaks spawn()

// Fix: encode unsafe characters
$name = 'order-' . str_replace(['/', ' '], ['-', '_'], $entityId);
```

**See also:** [Reference overview](../reference/overview.md)

---

### 10. `NoSenderException` from `$ctx->reply()`

**Symptom:** `NoSenderException` thrown when calling `$ctx->sender()` without an `Option` check, or when using a hypothetical `reply()` helper.

**Cause:** `$ctx->sender()` returns `Option<ActorRef>`. When an actor receives a message via `tell()` with no explicit sender (the common case), `sender()` is `None`. Calling `.get()` on a `None` throws.

**Fix:** Always check whether the sender is present before replying. Use the ask pattern for request-reply, which automatically wires the reply-to reference:

```php title="src/Actors/QueryActor.php"
// Wrong — throws if message was sent with tell()
$ctx->sender()->get()->tell(new Response($result));

// Correct — check presence first
$ctx->sender()->ifPresent(
    /** @param ActorRef<Response> $sender */
    static fn(ActorRef $sender) => $sender->tell(new Response($result)),
);
```

**See also:** [Ask pattern](../core-concepts/ask-pattern.md)

---

### 11. `UnresolvableParameterException` in HTTP handler

**Symptom:** `UnresolvableParameterException: Cannot resolve ServerRequestInterface parameter $request` or similar, thrown when a route is matched.

**Cause:** The HTTP handler parameter resolver does not know how to inject the parameter. The resolver chain supports: `ServerRequestInterface` (type-hint only), `#[FromBody]`, `#[FromActor('name')]`, `#[FromService(MyService::class)]`, route attributes, and query parameters. Any other type or combination fails.

**Fix:** Add the correct attribute to the handler parameter, or register a custom `ParamResolver` in the handler registry:

```php title="src/Http/Handlers/OrderHandler.php"
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;

/**
 * @param ActorRef<OrderCommand> $ordersActor
 */
public function handle(
    #[FromBody] CreateOrderRequest $body,
    #[FromActor('orders')] ActorRef $ordersActor,
): Response {
    // ...
}
```

**See also:** [HTTP handlers](../http/handlers.md)

---

### 12. Missing scope middleware (`MissingConnectionScopeException` / `MissingEntityManagerScopeException`)

**Symptom:** `MissingConnectionScopeException: No DBAL connection bound to the current coroutine` or `MissingEntityManagerScopeException` thrown inside a route handler.

**Cause:** The Doctrine DBAL or ORM scope middleware was not registered for the route. The middleware borrows a connection from the pool, binds it to the current coroutine context, and releases it when the request completes. Without it, handlers that call `$em->find()` or execute queries have no connection.

**Fix:** Register the scope middleware on your application or route group:

```php title="src/Http/App.php"
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;

$app
    ->middleware(new ConnectionScopeMiddleware($pool))
    ->middleware(new EntityManagerScopeMiddleware($emPool));
```

**See also:** [Doctrine DBAL integration](../doctrine/overview.md), [Doctrine ORM integration](../doctrine/overview.md)

---

### 13. `PoolExhaustedException` under load

**Symptom:** `PoolExhaustedException` thrown during concurrent requests; database operations fail with "pool exhausted" errors.

**Cause:** The Doctrine connection pool (DBAL `ConnectionPool` or ORM `EntityManagerPool`) has no idle connections available. Every coroutine waiting for a connection blocks until one is returned. When the wait queue itself fills, `PoolExhaustedException` is thrown.

**Fix:** Increase the pool size to match expected concurrency, or reduce connection hold time by keeping handlers fast and avoiding unnecessary transactions. The pool size should be at most the number of database connections your server allows:

```php title="src/Bootstrap/DoctrineSetup.php"
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPoolConfig;

$config = ConnectionPoolConfig::withSize(50) // match DB max_connections
    ->withAcquireTimeout(Duration::seconds(5));
```

**See also:** [Doctrine DBAL pool](../doctrine/overview.md)

---

### 14. Watcher misses `Terminated` (already-dead race)

**Symptom:** An actor calls `$ctx->watch($ref)` but never receives the `Terminated` signal, even though the watched actor stops.

**Cause:** If the watched actor dies before `watch()` is called, the `Terminated` signal is never delivered. Death watch only notifies watchers that were registered while the actor was alive.

**Fix:** Establish the death watch immediately after spawning the child, before any message that could cause it to stop:

```php title="src/Actors/SupervisorActor.php"
$child = $ctx->spawn(Props::fromBehavior($childBehavior), 'child');
$ctx->watch($child); // register immediately — before any tell()
$child->tell(new Start());
```

If you need to watch an actor whose lifecycle you do not control, use `$ref->isAlive()` as a polling fallback alongside the watch.

---

### 15. Blocking-call Psalm rule violation

**Symptom:** Psalm reports `BlockingCallInActorHandler: Blocking call 'sleep' detected inside actor message handler`.

**Cause:** The `BlockingCallInHandlerRule` Psalm plugin flags calls to `sleep()`, `usleep()`, `file_get_contents()`, `curl_exec()`, `PDO::query()`, and other blocking functions inside `Behavior::receive()` closures or `ActorHandler::handle()` implementations. These calls block the PHP Fiber or Swoole coroutine, preventing all other actors from processing messages.

**Fix:** Move blocking I/O outside the handler using `scheduleOnce`, or replace blocking calls with non-blocking alternatives (Swoole coroutine-aware HTTP/DB clients):

```php title="src/Actors/FetcherActor.php"
// Wrong — blocks the fiber
$html = file_get_contents('https://example.com');

// Correct — schedule work outside the handler
$ctx->scheduleOnce(Duration::zero(), static function () use ($ctx, $url): void {
    $client = new Swoole\Coroutine\Http\Client('example.com', 80);
    $client->get($url);
    $ctx->self()->tell(new FetchComplete($client->body));
});

return Behavior::same();
```

**See also:** [Best practices](../best-practices/overview.md), [Reference overview](../reference/overview.md), [Swoole deadlock-detector false positive](./swoole-deadlock-detector.md) — the periodic `Worker_reactor_try_to_exit()` cycle on idle Swoole workers.
