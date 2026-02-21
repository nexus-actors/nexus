# DDD Framework Design — `nexus-ddd`

## Problem

Nexus provides powerful actor-model primitives (behaviors, event sourcing, durable state, supervision, clustering) but requires users to manually wire DDD tactical patterns. There are no dedicated abstractions for Aggregates, Commands, Queries, Process Managers, Projections, or Value Objects. Users must compose raw `EventSourcedBehavior`, `Props`, and `ActorRef` to achieve DDD patterns, resulting in boilerplate and inconsistency.

## Solution

A new `nexus-ddd` package providing Ecotone-style DDD tactical pattern classes on top of Nexus actors. Domain objects (Aggregates, Process Managers, Projectors, Event Handlers, Query Handlers) are plain PHP classes with attributes — zero infrastructure awareness. The framework wires persistence, transport, and lifecycle externally.

## Design Principles

1. **Domain purity** — Aggregates, Process Managers, and all domain objects have zero knowledge of infrastructure (persistence, outbox, transport, event bus wiring). All infra is configured externally.
2. **Attributes + explicit** — Every pattern supports both attribute-based discovery (`#[CommandHandler]`, `#[EventHandler]`) and explicit class-based wiring (classic `AddItemHandler` with injected repository).
3. **Thin façade** — Each DDD class maps to a Nexus actor primitive internally. No new concurrency model, no new persistence layer — just DDD vocabulary over proven Nexus internals.
4. **Convention-based routing** — Commands declare their target via `targetAggregateId()`. Queries route by type. Events fan out to all subscribers.
5. **Container-first** — Process Managers, Projectors, Event Handlers, Query Handlers are instantiated from PSR-11 containers with dependency injection. Aggregates are NOT injectable — they are pure domain.

## Package Structure

```
packages/nexus-ddd/
├── composer.json              # requires nexus-core, nexus-persistence
├── src/
│   ├── Aggregate/
│   │   ├── Attribute/
│   │   │   ├── Aggregate.php            #[Aggregate]
│   │   │   ├── Identifier.php           #[Identifier]
│   │   │   ├── CommandHandler.php        #[CommandHandler]
│   │   │   ├── ApplyEvent.php            #[ApplyEvent]
│   │   │   └── Repository.php            #[Repository]
│   │   ├── AggregateRoot.php             abstract base class
│   │   ├── AggregateId.php               value object (type + id)
│   │   └── AggregateRepository.php       interface
│   │
│   ├── Command/
│   │   ├── Attribute/
│   │   │   └── Asynchronous.php          #[Asynchronous]
│   │   ├── Command.php                   interface
│   │   └── CommandBus.php                interface
│   │
│   ├── Query/
│   │   ├── Attribute/
│   │   │   └── QueryHandler.php          #[QueryHandler]
│   │   ├── Query.php                     interface (generic R)
│   │   └── QueryBus.php                  interface
│   │
│   ├── Event/
│   │   ├── Attribute/
│   │   │   └── EventHandler.php          #[EventHandler]
│   │   ├── DomainEvent.php               marker interface
│   │   ├── EventBus.php                  interface
│   │   └── MessageHeaders.php            metadata propagation
│   │
│   ├── ProcessManager/
│   │   ├── Attribute/
│   │   │   └── OnEvent.php               #[OnEvent(EventClass)]
│   │   ├── ProcessManager.php            abstract base class
│   │   ├── ProcessId.php                 value object
│   │   └── ProcessEffect.php             dispatch/deadline/schedule/complete
│   │
│   ├── Projection/
│   │   ├── Attribute/
│   │   │   └── Projection.php            #[Projection]
│   │   ├── Projector.php                 abstract base class
│   │   └── ProjectionPosition.php        tracks last processed event
│   │
│   ├── ValueObject/
│   │   ├── ValueObject.php               abstract — equals(), structural equality
│   │   └── SingleValueObject.php         abstract — single scalar wrapper
│   │
│   ├── Interceptor/
│   │   ├── Attribute/
│   │   │   ├── Before.php                #[Before(pointcut:)]
│   │   │   ├── After.php                 #[After(pointcut:)]
│   │   │   └── Around.php                #[Around(pointcut:)]
│   │   └── MethodInvocation.php          interface — proceed()
│   │
│   ├── Outbox/
│   │   ├── OutboxStore.php               interface
│   │   └── OutboxRelay.php               relay actor (polls + publishes)
│   │
│   ├── Configuration/
│   │   └── NexusDdd.php                  framework wiring entry point
│   │
│   └── Exception/
│       ├── AggregateNotFoundException.php
│       ├── CommandRejected.php
│       ├── DuplicateAggregateException.php
│       ├── ProcessManagerException.php
│       └── ProjectionException.php
│
└── tests/
    └── Unit/
```

**Dependency graph:**
```
nexus-ddd → nexus-core + nexus-persistence
```

No dependency on specific runtimes, serialization, or cluster packages.

## Aggregates

Aggregates are pure domain objects. No injected services. No infrastructure awareness. They collect domain events internally via `recordEvent()`, which are flushed by the framework after command handling.

Two persistence modes configured externally: event-sourced or state-stored.

### Aggregate Definition

```php
#[Aggregate]
final class ShoppingCart extends AggregateRoot
{
    #[Identifier]
    private string $cartId;

    private CartState $state;

    // Static factory — creates new aggregate
    #[CommandHandler]
    public static function create(CreateCart $command): self
    {
        $cart = new self();
        $cart->recordEvent(new CartCreated($command->cartId));

        return $cart;
    }

    // Instance method — modifies existing aggregate
    #[CommandHandler]
    public function addItem(AddItem $command): void
    {
        if ($this->state->itemCount() >= 50) {
            throw new CartLimitExceeded();
        }

        $this->recordEvent(new ItemAdded($command->item, $command->price));
        $this->recordEvent(new CartTotalRecalculated($this->state->total()));
    }

    // Event application — called immediately after recordEvent()
    #[ApplyEvent]
    public function onCartCreated(CartCreated $event): void
    {
        $this->cartId = $event->cartId;
        $this->state = new CartState();
    }

    #[ApplyEvent]
    public function onItemAdded(ItemAdded $event): void
    {
        $this->state = $this->state->withItem($event->item);
    }

    #[ApplyEvent]
    public function onTotalRecalculated(CartTotalRecalculated $event): void
    {
        $this->state = $this->state->withTotal($event->total);
    }
}
```

### AggregateRoot Base Class

```php
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    protected function recordEvent(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
        $this->apply($event); // immediately calls matching #[ApplyEvent] method
    }

    /** @internal Called by framework to flush events after command handling */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }

    /** Dispatches event to the matching #[ApplyEvent] method via reflection */
    private function apply(DomainEvent $event): void
    {
        // Framework resolves method by event type → #[ApplyEvent] attribute
    }
}
```

### AggregateId

```php
final readonly class AggregateId
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function of(string $type, string $id): self
    {
        return new self($type, $id);
    }

    /** Maps to nexus-persistence PersistenceId internally */
    public function toPersistenceId(): PersistenceId
    {
        return PersistenceId::of($this->type, $this->id);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function toString(): string
    {
        return "{$this->type}|{$this->id}";
    }
}
```

### AggregateRepository

```php
interface AggregateRepository
{
    /**
     * @template T of AggregateRoot
     * @param class-string<T> $aggregateClass
     * @return T
     * @throws AggregateNotFoundException
     */
    public function load(string $aggregateClass, AggregateId $id): AggregateRoot;

    public function save(AggregateRoot $aggregate): void;
}
```

### Three Styles of Command Handling

**Style 1: Attribute on aggregate method (Ecotone-style)**

```php
#[Aggregate]
final class ShoppingCart extends AggregateRoot
{
    #[CommandHandler]
    public function addItem(AddItem $command): void
    {
        $this->recordEvent(new ItemAdded($command->item));
    }
}
```

Framework auto-loads aggregate by `$command->targetAggregateId()`, invokes method, saves.

**Style 2: Explicit handler class with injected repository (classic DDD)**

```php
final class AddItemHandler
{
    public function __construct(
        private readonly AggregateRepository $carts,
    ) {}

    public function __invoke(AddItem $command): void
    {
        $cart = $this->carts->load(ShoppingCart::class, $command->cartId);
        $cart->addItem($command);
        $this->carts->save($cart);
    }
}
```

User controls load/save lifecycle. Full flexibility.

**Style 3: External service handler (non-aggregate commands)**

```php
final class SendWelcomeEmailHandler
{
    public function __construct(
        private readonly Mailer $mailer,
    ) {}

    #[CommandHandler]
    public function handle(SendWelcomeEmail $command): void
    {
        $this->mailer->send($command->email, 'Welcome!');
    }
}
```

Injectable service, no aggregate involved.

## Commands

```php
interface Command
{
    public function targetAggregateId(): AggregateId;
}

readonly class AddItem implements Command
{
    public function __construct(
        public AggregateId $cartId,
        public string $item,
        public int $price,
    ) {}

    public function targetAggregateId(): AggregateId
    {
        return $this->cartId;
    }
}
```

### CommandBus

```php
interface CommandBus
{
    /** Dispatches command to the single registered handler */
    public function dispatch(Command $command): void;
}
```

Internally implemented as an actor that:
1. Extracts `targetAggregateId()` from the command
2. Resolves the aggregate actor (spawns on demand or looks up existing)
3. Sends the command via `$actorRef->tell()`

## Queries

```php
/** @template R */
interface Query {}

/** @template R */
interface QueryBus
{
    /**
     * @template R
     * @param Query<R> $query
     * @return R
     */
    public function ask(Query $query): mixed;
}
```

### QueryHandler

```php
final class GetOrderSummaryHandler
{
    public function __construct(
        private readonly OrderReadRepository $orders,
    ) {}

    #[QueryHandler]
    public function handle(GetOrderSummary $query): OrderSummaryDto
    {
        return $this->orders->findById($query->orderId)
            ?? throw new OrderNotFoundException($query->orderId);
    }
}
```

QueryBus routes by query type → single registered `#[QueryHandler]`. Injectable from container.

## Domain Events

```php
interface DomainEvent {}

readonly class ItemAdded implements DomainEvent
{
    public function __construct(
        public string $item,
        public int $price,
    ) {}
}
```

### EventBus

```php
interface EventBus
{
    /** Publish to all subscribers (fan-out) */
    public function publish(DomainEvent $event, ?MessageHeaders $headers = null): void;

    /** Schedule event publication at a future time */
    public function schedulePublish(Duration $delay, DomainEvent $event): void;
}
```

### Event Handlers

```php
final class NotificationHandler
{
    public function __construct(
        private readonly Mailer $mailer,
    ) {}

    #[EventHandler]
    public function onOrderPlaced(OrderPlaced $event, MessageHeaders $headers): void
    {
        $this->mailer->send($event->customerEmail, 'Order confirmed!');
    }
}
```

Multiple handlers can subscribe to the same event. Each runs independently.

### MessageHeaders

```php
final readonly class MessageHeaders
{
    /** Propagated automatically through the message chain */
    public function get(string $key): mixed;
    public function has(string $key): bool;
    public function all(): array;

    public static function create(array $headers = []): self;
    public function with(string $key, mixed $value): self;
}
```

## Process Managers

Process Managers coordinate long-running business workflows across multiple aggregates. They react to domain events, dispatch commands, and support deadlines (timeouts) and event scheduling.

**Injectable from container.** State loaded/saved by framework — PM does not know about persistence.

```php
final class OrderFulfillment extends ProcessManager
{
    public function __construct(
        private readonly InventoryClient $inventory,
    ) {}

    public function processId(): ProcessId
    {
        return ProcessId::of('OrderFulfillment', $this->orderId);
    }

    #[OnEvent(OrderPlaced::class)]
    public function onOrderPlaced(OrderPlaced $event): ProcessEffect
    {
        return ProcessEffect::dispatch(
            new ReserveStock($event->items),
        )->withDeadline(
            'payment-timeout',
            Duration::minutes(30),
            new PaymentTimedOut($event->orderId),
        );
    }

    #[OnEvent(PaymentReceived::class)]
    public function onPaymentReceived(PaymentReceived $event): ProcessEffect
    {
        return ProcessEffect::cancelDeadline('payment-timeout')
            ->dispatch(new ShipOrder($event->orderId));
    }

    #[OnEvent(PaymentTimedOut::class)]
    public function onTimeout(PaymentTimedOut $event): ProcessEffect
    {
        return ProcessEffect::dispatch(
            new ReleaseStock($this->items),
        )->completed();
    }

    #[OnEvent(StockReservationFailed::class)]
    public function onStockFailed(StockReservationFailed $event): ProcessEffect
    {
        return ProcessEffect::dispatch(
            new CancelOrder($event->orderId, 'Out of stock'),
        )->cancelDeadline('payment-timeout')
        ->completed();
    }
}
```

### ProcessEffect

```php
final readonly class ProcessEffect
{
    public static function dispatch(Command ...$commands): self;
    public static function none(): self;
    public static function cancelDeadline(string $name): self;

    public function dispatch(Command ...$commands): self;
    public function withDeadline(string $name, Duration $timeout, DomainEvent $event): self;
    public function cancelDeadline(string $name): self;
    public function scheduleEvent(Duration $delay, DomainEvent $event): self;
    public function completed(): self;  // marks PM as finished
}
```

Deadlines map to `$ctx->scheduleOnce()` internally. Deadline events are delivered to the same PM. Named deadlines are cancellable and persisted with PM state to survive crashes.

Event scheduling publishes a domain event to the EventBus after a delay.

### Persistence Strategy

Configured externally — the PM has zero persistence awareness:

```php
$config->processManager(OrderFulfillment::class)
    ->stateStored($doctrineRepository);     // or ->eventSourced($eventStore)
                                             // or ->inMemory()
```

## Projections

Projectors build read models from domain events. Injectable from container. The framework manages event polling, position tracking, and idempotent replay.

```php
#[Projection('order-summary')]
final class OrderSummaryProjector extends Projector
{
    public function __construct(
        private readonly OrderReadRepository $repository,
    ) {}

    protected function subscribesTo(): array
    {
        return [
            OrderPlaced::class,
            OrderShipped::class,
            OrderCancelled::class,
        ];
    }

    #[EventHandler]
    public function onOrderPlaced(OrderPlaced $event, MessageHeaders $headers): void
    {
        $this->repository->insert([
            'order_id' => $event->orderId,
            'customer' => $event->customerId,
            'status' => 'placed',
            'total' => $event->total,
            'placed_at' => $headers->get('timestamp'),
        ]);
    }

    #[EventHandler]
    public function onOrderShipped(OrderShipped $event): void
    {
        $this->repository->update($event->orderId, ['status' => 'shipped']);
    }

    #[EventHandler]
    public function onOrderCancelled(OrderCancelled $event): void
    {
        $this->repository->update($event->orderId, ['status' => 'cancelled']);
    }
}
```

### ProjectionPosition

```php
final readonly class ProjectionPosition
{
    public function __construct(
        public string $projectionName,
        public int $lastProcessedSequenceNr,
    ) {}
}
```

Tracked by the framework. On restart, projectors resume from last position.

## Value Objects

### ValueObject (composite)

```php
abstract readonly class ValueObject
{
    /** Structural equality — compares all public properties recursively */
    public function equals(self $other): bool
    {
        if (static::class !== $other::class) {
            return false;
        }

        foreach ((new ReflectionClass(static::class))->getProperties() as $prop) {
            $a = $prop->getValue($this);
            $b = $prop->getValue($other);

            if ($a instanceof self && $b instanceof self) {
                if (!$a->equals($b)) {
                    return false;
                }
            } elseif ($a !== $b) {
                return false;
            }
        }

        return true;
    }

    public function toString(): string;
}
```

### SingleValueObject (scalar wrapper)

```php
/** @template T of string|int|float|bool */
abstract readonly class SingleValueObject extends ValueObject
{
    /** @param T $value */
    public function __construct(
        public string|int|float|bool $value,
    ) {
        $this->validate($value);
    }

    /** Override to add validation. Throw on invalid. */
    protected function validate(string|int|float|bool $value): void {}
}
```

### Examples

```php
final readonly class Email extends SingleValueObject
{
    protected function validate(string|int|float|bool $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw InvalidEmail::fromString((string) $value);
        }
    }
}

final readonly class Money extends ValueObject
{
    public function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    public function add(self $other): self
    {
        $this->currency->equals($other->currency)
            || throw CurrencyMismatch::between($this->currency, $other->currency);

        return new self($this->amount + $other->amount, $this->currency);
    }
}

final readonly class Currency extends SingleValueObject
{
    public const EUR = 'EUR';
    public const USD = 'USD';

    protected function validate(string|int|float|bool $value): void
    {
        if (!in_array($value, ['EUR', 'USD', 'GBP', 'CZK'], true)) {
            throw new InvalidCurrency((string) $value);
        }
    }
}

// Nesting — structural equality works recursively
$a = new Money(100, new Currency('EUR'));
$b = new Money(100, new Currency('EUR'));
$a->equals($b); // true
```

## Interceptors

Cross-cutting concerns (auth, transactions, logging, validation) via attribute-based middleware. Three types with different execution points.

### Before

Runs before the handler. Can validate, enrich, or abort.

```php
#[Before(pointcut: RequiresAuth::class)]
public function checkAuth(object $message, MessageHeaders $headers): void
{
    if (!$headers->has('userId')) {
        throw new Unauthorized('Authentication required');
    }
}
```

- Void return → continues to handler
- Non-void return → replaces message payload
- Throw → aborts handler execution

### Around

Wraps handler execution. Access to `MethodInvocation` to proceed or short-circuit.

```php
#[Around(pointcut: Transactional::class)]
public function wrapInTransaction(MethodInvocation $invocation): mixed
{
    $this->em->beginTransaction();

    try {
        $result = $invocation->proceed();
        $this->em->commit();

        return $result;
    } catch (\Throwable $e) {
        $this->em->rollBack();

        throw $e;
    }
}
```

### After

Runs after handler completes. Can enrich results or trigger side effects.

```php
#[After(pointcut: LoggableCommand::class)]
public function logCommand(object $message, MessageHeaders $headers): void
{
    $this->logger->info('Command handled', [
        'type' => $message::class,
        'userId' => $headers->get('userId'),
    ]);
}
```

### Pointcut Targeting

Pointcuts reference attributes or class patterns:

```php
// Target all handlers marked with a specific attribute
#[Before(pointcut: RequiresAuth::class)]

// Target all command handlers
#[Before(pointcut: CommandHandler::class)]

// Target specific aggregate
#[Around(pointcut: ShoppingCart::class)]
```

### MethodInvocation

```php
interface MethodInvocation
{
    public function proceed(): mixed;
    public function getArguments(): array;
    public function getTarget(): object;
    public function getMethodName(): string;
}
```

## Outbox

Prevents dual-write problem. Events and outbox entries are committed in the same database transaction. A relay actor asynchronously publishes pending events with at-least-once delivery guarantee.

**No domain object knows about the outbox.** Configured externally.

### Flow

```
Command → Aggregate → recordEvent()
                         │
                    Same DB transaction:
                    ┌─────────────────────┐
                    │ 1. Persist events    │
                    │ 2. Write to outbox   │
                    └─────────────────────┘
                         │
                    OutboxRelay actor (async):
                    ┌─────────────────────┐
                    │ 3. Poll outbox table │
                    │ 4. Publish to EventBus│
                    │ 5. Mark as published │
                    └─────────────────────┘
```

### OutboxStore

```php
interface OutboxStore
{
    /** Store events in outbox (called within same transaction as aggregate persist) */
    public function store(OutboxEntry ...$entries): void;

    /** Fetch pending unpublished entries (batch) */
    public function fetchPending(int $batchSize = 100): array;

    /** Mark entries as published */
    public function markPublished(string ...$entryIds): void;
}
```

### Configuration

```php
$config->aggregate(ShoppingCart::class)
    ->eventSourced($eventStore)
    ->withOutbox($outboxStore);

// Or globally
$config->defaultOutbox($outboxStore);
```

## Framework Configuration

All infrastructure wiring happens in one place. Domain objects remain pure.

```php
NexusDdd::configure($system)
    // Aggregates
    ->aggregate(ShoppingCart::class)
        ->eventSourced($eventStore)
        ->withSnapshots($snapshotStore, SnapshotStrategy::everyN(10))
        ->withOutbox($outboxStore)
    ->aggregate(UserProfile::class)
        ->stateStored($doctrineRepository)

    // Process Managers
    ->processManager(OrderFulfillment::class)
        ->stateStored($doctrineRepository)
    ->processManager(PaymentRetry::class)
        ->eventSourced($eventStore)

    // Projections
    ->projection(OrderSummaryProjector::class)
        ->from($eventStore)
    ->projection(InventoryProjector::class)
        ->from($eventStore)

    // External handlers (injected from container)
    ->handler(SendWelcomeEmailHandler::class)
    ->handler(GetOrderSummaryHandler::class)

    // Interceptors
    ->interceptor(AuthInterceptor::class)
    ->interceptor(TransactionInterceptor::class)
    ->interceptor(LoggingInterceptor::class)

    // Global outbox
    ->defaultOutbox($outboxStore)

    // PSR-11 container for dependency injection
    ->withContainer($container)

    ->build();
```

## Internal Mapping to Nexus Primitives

| DDD Concept | Nexus Implementation |
|---|---|
| Aggregate (ES) | Actor with `EventSourcedBehavior` |
| Aggregate (state-stored) | Actor with `DurableStateBehavior` |
| AggregateId | `PersistenceId` |
| CommandBus | Router actor — resolves aggregate by ID, spawns on demand |
| QueryBus | Router actor — resolves handler by query type |
| EventBus | Fan-out actor — publishes to all subscriber actors |
| ProcessManager | Actor with state (ES/Durable/InMemory), subscribes to EventBus |
| Projector | Actor subscribed to EventBus, tracks position |
| Deadline | `$ctx->scheduleOnce(duration, deadlineEvent)` |
| Event scheduling | `$ctx->scheduleOnce(duration, fn() => $eventBus->publish(...))` |
| Interceptors | Wrapper behaviors around handler behaviors |
| Outbox | OutboxRelay actor with `scheduleRepeatedly()` for polling |
| MessageHeaders | `Envelope::$metadata` |

## Testing

All DDD patterns must be testable with `StepRuntime` (deterministic, no real time):

```php
// Given-When-Then for aggregates
$test = AggregateTest::for(ShoppingCart::class)
    ->given(new CartCreated('cart-1'))
    ->when(new AddItem(AggregateId::of('ShoppingCart', 'cart-1'), 'book', 1500))
    ->then(
        new ItemAdded('book', 1500),
        new CartTotalRecalculated(1500),
    );

// Process Manager testing
$test = ProcessManagerTest::for(OrderFulfillment::class)
    ->given(new OrderPlaced('order-1', ['book']))
    ->thenDispatched(new ReserveStock(['book']))
    ->andDeadlineScheduled('payment-timeout', Duration::minutes(30));
```

## Non-Goals

- No new persistence engine — reuses `nexus-persistence` event stores and snapshot stores
- No new concurrency model — reuses Nexus runtimes (Fiber, Swoole, Step)
- No code generation or compile step — pure PHP attributes + reflection
- No dependency on specific database (Doctrine, DBAL) — via interfaces only
