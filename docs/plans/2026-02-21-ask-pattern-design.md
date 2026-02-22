# Ask Pattern Design

## Summary

Implement the `ask()` request-response pattern on `ActorRef` for both local (same process) and cross-worker (via transport) communication. `ActorRef` in messages works transparently — same user code for local and remote actors. Zero static state, zero singletons, no cluster concepts in core.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Reply-to type | `ActorRef` everywhere | Location transparency — same user code local or remote |
| Serialization | Serializer-managed conversion | No static state; serializer converts ActorRef <-> PortableActorRef using injected dependencies |
| Static state | None | All dependencies via constructor injection; serializer is the resolution boundary |
| Nested ActorRef | Disallowed | Psalm plugin enforces ActorRef as direct message constructor params only |
| Reflection | Cached per message class | First call ~10us, subsequent <1us via hash lookup |

## Package Boundaries

```
nexus-core (NO cluster knowledge)
  ActorRef (interface) — ask() defined here
  LocalActorRef — ask() implemented for local actors
    Needs: Runtime (to create temp mailbox + schedule timeout)

nexus-cluster (knows about routing)
  RemoteActorRef — ask() implemented for remote actors
    Needs: Runtime, ClusterNode, Transport
  PortableActorRef — serializable value object (path + workerId)
  CompactClusterSerializer — converts ActorRef <-> PortableActorRef
    Needs: workerId, ClusterNode, Transport, ActorDirectory (all injected)
```

## New Classes

### PortableActorRef (nexus-cluster)

Tiny serializable value object. Survives PHP `serialize()` trivially.

```php
final readonly class PortableActorRef
{
    public function __construct(
        public string $path,
        public int $workerId,
    ) {}
}
```

## Modified Classes

### LocalActorRef (nexus-core)

Add `?Runtime` as optional 4th constructor parameter. Implement `ask()` for local actors.

```php
public function __construct(
    private ActorPath $path,
    private Mailbox $mailbox,
    private Closure $aliveChecker,
    private ?Runtime $runtime = null,   // NEW
) {}
```

`ask()` implementation:
1. Create temp mailbox (bounded, capacity 1)
2. Create temp `LocalActorRef` wrapping the temp mailbox
3. Call `$messageFactory($replyToRef)` to build the request message
4. `$this->tell($message)` to send to target actor
5. Schedule timeout timer that closes the temp mailbox
6. `$tempMailbox->dequeueBlocking($timeout)` — blocks current fiber/coroutine
7. On reply: cancel timer, close mailbox, return `$envelope->message`
8. On timeout: `MailboxClosedException` caught, rethrown as `AskTimeoutException`

### ActorCell (nexus-core)

One-line change: pass `$this->runtime` when constructing `selfRef`.

```php
// Before:
$this->selfRef = new LocalActorRef($this->actorPath, $this->mailbox, fn() => $this->isAlive());

// After:
$this->selfRef = new LocalActorRef($this->actorPath, $this->mailbox, fn() => $this->isAlive(), $this->runtime);
```

### RemoteActorRef (nexus-cluster)

Implement `ask()` for cross-worker request-response.

1. Get runtime from `ClusterNode.system().runtime()`
2. Create temp mailbox on THIS worker
3. Create temp `LocalActorRef` for the temp mailbox
4. Register temp ref in `ClusterNode.localRefs` (so transport replies find it)
5. Call `$messageFactory($tempLocalRef)` — reply-to is a `LocalActorRef`
6. `$this->tell($message)` — serializer converts `LocalActorRef` to `PortableActorRef` during send
7. Schedule timeout + `$tempMailbox->dequeueBlocking($timeout)`
8. On reply: cancel timer, close mailbox, unregister temp ref, return message
9. On timeout: unregister temp ref, throw `AskTimeoutException`

### ClusterNode (nexus-cluster)

Add temp ref registration for ask() replies:

```php
public function registerTempRef(ActorPath $path, LocalActorRef $ref): void
{
    $this->localRefs[(string) $path] = $ref;
}

public function unregisterTempRef(ActorPath $path): void
{
    unset($this->localRefs[(string) $path]);
}
```

The existing `start()` transport listener already routes by path in `localRefs`, so transport replies to `/temp/ask-*` automatically reach the temp mailbox without changes.

### CompactClusterSerializer (nexus-cluster)

Enhanced constructor with cluster dependencies:

```php
public function __construct(
    private readonly int $workerId,
    private readonly ClusterNode $node,
    private readonly Transport $transport,
    private readonly ActorDirectory $directory,
) {}
```

Add `makePortable()` and `resolveRefs()`:

**Serialize path:**
1. Reflect on message class constructor params (cached per class)
2. Find params typed as `ActorRef`
3. Replace with `PortableActorRef(path, workerId)`
4. Reconstruct message via `newInstanceArgs()`
5. PHP `serialize()` the portable message

**Deserialize path:**
1. PHP `unserialize()` the message (contains `PortableActorRef` instances)
2. Find `PortableActorRef` instances in constructor params
3. Resolve to live `ActorRef`:
   - Same worker -> lookup in `ClusterNode.localRefs`, return `LocalActorRef`
   - Different worker -> create `RemoteActorRef` with injected transport
4. Reconstruct message via `newInstanceArgs()`

**Reflection cache:** `array<class-string, list<int>>` mapping message class to positions of `ActorRef`-typed constructor params. Populated on first encounter, O(1) lookup thereafter.

## Cross-Worker ask() Flow

```
Worker 0 (HTTP request)                     Worker 1 (owns "orders")
───────────────────────                     ────────────────────────

1. ref = node.actorFor('/user/orders')
   -> RemoteActorRef(path, worker=1)

2. ref.ask(fn($replyTo) =>
     new GetOrder('42', $replyTo), 5s)

3. Create temp mailbox + LocalActorRef
   Register /temp/ask-0 in localRefs

4. messageFactory(localReplyRef)
   -> GetOrder { id:"42", replyTo: LocalActorRef }

5. tell() -> serializer.serialize()
   makePortable: LocalActorRef -> PortableActorRef("/temp/ask-0", 0)
   Reconstruct: GetOrder { id:"42", replyTo: PortableActorRef }
   transport.send(worker=1, bytes)  ------->

                                            6. transport.listen receives bytes
                                               serializer.deserialize()
                                               resolveRefs: PortableActorRef(path, worker=0)
                                               worker 0 != myWorker(1)
                                               -> RemoteActorRef(/temp/ask-0, worker=0)
                                               Reconstruct: GetOrder { id:"42",
                                                 replyTo: RemoteActorRef }

                                            7. Actor handles GetOrder:
                                               $msg->replyTo->tell(new OrderResult(...))
                                               -> RemoteActorRef.tell()
                                               -> transport.send(worker=0, bytes)

8. transport.listen receives bytes  <-------
   target = /temp/ask-0
   localRefs["/temp/ask-0"] -> tempLocalRef
   tempLocalRef.enqueueEnvelope()
   -> temp mailbox receives OrderResult

9. dequeueBlocking() returns
   cancel timer, close mailbox
   unregister /temp/ask-0
   return OrderResult
```

## Local ask() Flow

```
Same process (no serialization, no transport)
─────────────────────────────────────────────

1. ref.ask(fn($replyTo) => new GetOrder('42', $replyTo), 5s)
2. Create temp mailbox + temp LocalActorRef
3. GetOrder { replyTo: LocalActorRef(tempMailbox) }
4. tell() -> enqueue to target's mailbox
5. Target fiber processes, calls replyTo.tell(OrderResult)
   -> enqueue to temp mailbox
6. dequeueBlocking() returns OrderResult
7. cancel timer, close mailbox, return result
```

## Psalm Plugin Enhancement

New rule: `NoNestedActorRefRule`

`ActorRef` properties must only appear as direct constructor parameters of message classes. No nesting inside sub-objects.

```php
// ALLOWED:
readonly class GetOrder {
    public function __construct(
        public string $id,
        public ActorRef $replyTo,  // direct param
    ) {}
}

// REJECTED:
readonly class Wrapper {
    public function __construct(public ActorRef $ref) {}
}
readonly class GetOrder {
    public function __construct(public Wrapper $w) {} // nested ActorRef
}
```

## Changes Summary

| File | Package | Change |
|---|---|---|
| `LocalActorRef.php` | nexus-core | Add `?Runtime` param, implement `ask()` |
| `ActorCell.php` | nexus-core | Pass `$this->runtime` to `LocalActorRef` constructor (1 line) |
| `RemoteActorRef.php` | nexus-cluster | Implement `ask()` with temp ref registration |
| `ClusterNode.php` | nexus-cluster | Add `registerTempRef()` / `unregisterTempRef()` |
| `CompactClusterSerializer.php` | nexus-cluster | Add `makePortable()` / `resolveRefs()` with cached reflection |
| `PortableActorRef.php` (new) | nexus-cluster | Tiny readonly VO (path + workerId) |
| Psalm plugin (new rule) | nexus-psalm | `NoNestedActorRefRule` |

## Constraints

- Zero static state
- Zero singletons
- Core knows nothing about cluster
- All dependencies via constructor injection
- Reflection cached per message class
- Only top-level ActorRef properties (no nesting)
