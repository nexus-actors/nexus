# Nexus Fulfillment — Plan 2: Orders Vertical Slice

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The first end-to-end domain slice: an event-sourced `Order` entity actor (place/cancel with idempotency and passivation), the `ContextBus` fan-out seam, an `orders_view` Doctrine read model maintained by a projector actor, and authenticated HTTP endpoints — place, list, get, cancel — working live against Postgres.

**Architecture:** `src/Orders/{Domain,Application,Infrastructure}` per the Deptrac layers laid in Plan 1. Domain is pure (DECIDE = `OrderRules`, EVOLVE = `OrderState::evolve`); Application hosts the actor shell (`OrderActor`, `OrderRefFactory`); Infrastructure hosts the read model and HTTP handlers. Cross-context publication goes through a new `ContextBus` actor in Platform (the future Messenger seam). Events persist through the pool-backed Doctrine event store with the **Valinor serializer from Plan 1** — wire names are the registry's `orders.*.v1` types.

**Tech Stack:** Everything from Plan 1, plus `doctrine/dbal ^4.0` + `doctrine/orm ^3.0`, nexus-persistence(-doctrine), nexus-doctrine-dbal/-orm pools, nexus-http-auth.

**Spec:** `docs/superpowers/specs/2026-07-03-nexus-fulfillment-example-design.md` — this implements the "Orders vertical slice" milestone (2 of 8).

## Global Constraints

- **Branch:** continue on `feat/fulfillment-example` in the worktree `.claude/worktrees/fulfillment` (PR #51). If PR #51 has merged, create a fresh worktree/branch from `main` via superpowers:using-git-worktrees and cherry-pick this plan doc.
- **Never run `php`/`composer`/`vendor/bin/*` on the host.** Everything through `cd examples/nexus-fulfillment && make <target>` or `docker compose run --rm app <cmd>`. `make test` uses `run --rm`, never `exec`.
- **GrumPHP worktree caveat:** if the pre-commit hook fails in the worktree, commit with `git commit --no-verify`; `make ci` is the real gate and must be green before every commit that claims it.
- **Never add `Co-Authored-By: Claude`** or any Claude attribution to commits.
- **Code style:** as Plan 1 (strict_types, final, readonly value objects/messages, PER-CS2.0, alphabetical string-key arrays, multi-line ternaries, blank line before control structures unless first statement, ordered imports, trailing commas). All actor-message classes MUST be `readonly` (nexus-psalm plugin enforces).
- **Mirror, don't invent:** the reference implementations for every Nexus API in this plan are `examples/nexus-wallet-app` (auth, handlers, `ask()->await()`, WalletActor's `EventSourcedBehavior` + sender-capture idiom) and `examples/nexus-tictactoe` (DoctrineKit pools, SchemaBootstrap, event-store construction, read-model upsert). When code in this plan disagrees with those files, THE EXAMPLES WIN — adapt minimally and record the adaptation in your report.
- **Wire names:** every persisted/published event carries `#[MessageType('orders.<name>.v1')]` and is listed in `MessageTypes::CONTRACTS`. The event store serializer is `ValinorMessageSerializer(MessageTypes::registry())` — an unregistered event class must fail loudly, not fall back.
- **Idempotency model:** the client supplies `orderId` (a ULID) in the request body — it IS the idempotency key. Retrying the same `orderId` re-addresses the same entity; `OrderRules` treats a duplicate `PlaceOrder` as idempotent success (no new events).
- **Retention keeps the full event log** (`RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: false)`) — Plan 7's projection rebuild replays it.
- **TDD** for all domain and actor code. YAGNI throughout: no Warehouse/Shipping/saga types yet.

---

### Task 1: Doctrine dependencies, pools, and persistence schema

**Files:**
- Modify: `examples/nexus-fulfillment/composer.json` (require + psr-4)
- Create: `examples/nexus-fulfillment/src/Platform/Boot/DoctrineKit.php`
- Create: `examples/nexus-fulfillment/src/Platform/Boot/SchemaBootstrap.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Boot/DbConfig.php` (add `toConnectionParams()`)
- Modify: `examples/nexus-fulfillment/src/Platform/Boot/App.php` (build kit + sync schema at worker boot)

**Interfaces:**
- Consumes: Plan 1's `DbConfig`, `App::factory`, compose `db` service.
- Produces: `DoctrineKit::build(DbConfig, ActorSystem, LoggerInterface): self` exposing `public ConnectionPool $connPool`, `public EntityManagerPool $emPool`, `public EventStore $eventStore`, `public SnapshotStore $snapshotStore`; `DbConfig::toConnectionParams(): array`; `SchemaBootstrap::sync(array $connParams, Configuration $ormConfig): void`. Tasks 4–6 depend on these exact names.

- [ ] **Step 1: Read the reference implementations**

Read fully before writing anything: `examples/nexus-tictactoe/src/Boot/DoctrineKit.php`, `examples/nexus-tictactoe/src/Boot/SchemaBootstrap.php`, `examples/nexus-tictactoe/src/Boot/DbConfig.php` (for `toConnectionParams`), and how tictactoe constructs its event store + snapshot store (search its DoctrineKit for `EventStore`). Your Task 1 code mirrors these with `Fulfillment` naming and the `orders_view` entity path added later (Task 5 updates the entity path list).

- [ ] **Step 2: Add dependencies and autoload mappings**

In `examples/nexus-fulfillment/composer.json`:
- `require`: add `"doctrine/dbal": "^4.0"` and `"doctrine/orm": "^3.0"` (keep alphabetical order).
- `autoload.psr-4`: add (alphabetically placed):

```json
"Monadial\\Nexus\\Doctrine\\Dbal\\": "/nexus-packages/nexus-doctrine-dbal/src/",
"Monadial\\Nexus\\Doctrine\\Orm\\": "/nexus-packages/nexus-doctrine-orm/src/",
"Monadial\\Nexus\\Persistence\\Doctrine\\": "/nexus-packages/nexus-persistence-doctrine/src/",
```

Verify each prefix against the package's own `composer.json` (as in Plan 1 Task 1). Then:

```bash
cd examples/nexus-fulfillment
docker compose run --rm app composer update --no-interaction --no-progress doctrine/dbal doctrine/orm
docker compose run --rm app composer validate
```

Expected: resolves cleanly, `composer.json is valid`.

- [ ] **Step 3: Implement DbConfig::toConnectionParams, DoctrineKit, SchemaBootstrap**

`DbConfig` gains (mirror tictactoe's return shape exactly — driver `pdo_pgsql`):

```php
    /**
     * @return array{dbname: string, driver: 'pdo_pgsql', host: string, password: string, port: int, user: string}
     */
    public function toConnectionParams(): array
    {
        return [
            'dbname' => $this->dbname,
            'driver' => 'pdo_pgsql',
            'host' => $this->host,
            'password' => $this->password,
            'port' => $this->port,
            'user' => $this->user,
        ];
    }
```

`src/Platform/Boot/DoctrineKit.php` — mirror tictactoe's `DoctrineKit::build()`: `ORMSetup::createAttributeMetadataConfig()` over an entity-paths list (start with the persistence journal/snapshot entity path exactly as tictactoe lists it; Task 5 adds `src/Orders/Infrastructure/ReadModel`), `SchemaBootstrap::sync(...)`, `DoctrinePool::fromParams('fulfillment-dbal', ..., new PoolConfig(max: 8, minIdle: 1))`, `DoctrineEmPool::forConfig('fulfillment-em', ..., new EmPoolConfig(max: 8, minIdle: 1))`, and construct the event store + snapshot store THE WAY TICTACTOE DOES — pool-backed Doctrine stores with a message serializer argument, passing `new ValinorMessageSerializer(MessageTypes::registry())` where tictactoe passes its serializer. Expose the four public readonly properties named in the Interfaces block.

`src/Platform/Boot/SchemaBootstrap.php` — mirror tictactoe's: idempotent `SchemaTool::updateSchema()` over the metadata list, catching the same already-exists exception types.

- [ ] **Step 4: Wire into App::factory**

In `App.php`, after the logger is built: `$doctrine = DoctrineKit::build($config->db, $system, $log);` (keep `$doctrine` local for now — Tasks 4–6 thread it into routes/actors). Keep `ReadinessProbe` as-is (plain PDO is fine for a probe).

- [ ] **Step 5: Verify boot creates the schema**

```bash
make up && sleep 5
docker compose exec db psql -U fulfillment -d fulfillment -c '\dt'
curl -fsS http://localhost:9090/readyz
make down
```

Expected: table list includes the persistence journal + snapshot tables (names as tictactoe's entities map them — likely `nexus_event_journal`/`nexus_snapshot_store` or the Doctrine entity table names; record what you see), readyz `{"status":"ready"}`.

- [ ] **Step 6: Run suite + commit**

Run: `make test` — 28 tests still green.

```bash
git add examples/nexus-fulfillment
git commit -m "feat(fulfillment): doctrine pools, persistence stores, schema bootstrap"
```

---

### Task 2: Orders domain — pure DECIDE/EVOLVE (TDD)

**Files:**
- Create: `examples/nexus-fulfillment/src/SharedKernel/Contracts/Orders/OrderCancelled.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Serialization/MessageTypes.php` (add OrderCancelled)
- Create: `examples/nexus-fulfillment/src/Orders/Domain/OrderStatus.php`
- Create: `examples/nexus-fulfillment/src/Orders/Domain/OrderState.php`
- Create: `examples/nexus-fulfillment/src/Orders/Domain/OrderRules.php`
- Create: `examples/nexus-fulfillment/src/Orders/Domain/Rejection.php`
- Create: `examples/nexus-fulfillment/src/Orders/Domain/Command/PlaceOrder.php`
- Create: `examples/nexus-fulfillment/src/Orders/Domain/Command/CancelOrder.php`
- Test: `examples/nexus-fulfillment/tests/Unit/Orders/Domain/{OrderRulesTest,OrderStateTest}.php`

**Interfaces:**
- Consumes: SharedKernel VOs, `OrderPlaced` contract.
- Produces (Tasks 4 and 6 rely on these exact shapes):
  - `OrderStatus` enum: `NotCreated`, `Placed`, `Cancelled` (string-backed: `'not_created'`, `'placed'`, `'cancelled'`).
  - `OrderState::empty(TenantId, OrderId): self`; `public OrderStatus $status`; `public ?Money $total`; `OrderState::evolve(self, object $event): self` (static pure fold).
  - `OrderRules::decide(OrderState, object $command): list<object>|Rejection` (static pure).
  - `Rejection::__construct(public string $reason)`.
  - `PlaceOrder::__construct(public TenantId $tenantId, public OrderId $orderId, public array $lines)` with `@param non-empty-list<OrderLine> $lines`; `CancelOrder::__construct(public TenantId $tenantId, public OrderId $orderId, public string $reason)`. Both `final readonly`.
  - `OrderCancelled::__construct(public TenantId $tenantId, public OrderId $orderId, public string $reason)` with `#[MessageType('orders.order_cancelled.v1')]`.

- [ ] **Step 1: Write failing OrderRules + OrderState tests**

`tests/Unit/Orders/Domain/OrderRulesTest.php` — decision table, one test per row:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Orders\Domain;

use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderRules;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderState;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Rejection;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderRules::class)]
final class OrderRulesTest extends TestCase
{
    private TenantId $tenant;
    private OrderId $orderId;

    /** @var non-empty-list<OrderLine> */
    private array $lines;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('acme');
        $this->orderId = OrderId::generate();
        $this->lines = [
            new OrderLine(Sku::fromString('WIDGET-42'), Quantity::of(2), Money::of(1999, 'EUR')),
        ];
    }

    #[Test]
    public function placingANewOrderEmitsOrderPlacedWithComputedTotal(): void
    {
        $decision = OrderRules::decide($this->emptyState(), $this->place());

        self::assertIsArray($decision);
        self::assertCount(1, $decision);
        self::assertInstanceOf(OrderPlaced::class, $decision[0]);
        self::assertTrue($decision[0]->total->equals(Money::of(3998, 'EUR')));
    }

    #[Test]
    public function placingTwiceIsIdempotentNoNewEvents(): void
    {
        $placed = OrderState::evolve($this->emptyState(), $this->placedEvent());

        self::assertSame([], OrderRules::decide($placed, $this->place()));
    }

    #[Test]
    public function placingACancelledOrderIsRejected(): void
    {
        $cancelled = $this->cancelledState();

        self::assertInstanceOf(Rejection::class, OrderRules::decide($cancelled, $this->place()));
    }

    #[Test]
    public function cancellingAPlacedOrderEmitsOrderCancelled(): void
    {
        $placed = OrderState::evolve($this->emptyState(), $this->placedEvent());

        $decision = OrderRules::decide($placed, new CancelOrder($this->tenant, $this->orderId, 'customer request'));

        self::assertIsArray($decision);
        self::assertInstanceOf(OrderCancelled::class, $decision[0]);
        self::assertSame('customer request', $decision[0]->reason);
    }

    #[Test]
    public function cancellingTwiceIsIdempotentNoNewEvents(): void
    {
        $cancelled = $this->cancelledState();

        self::assertSame([], OrderRules::decide($cancelled, new CancelOrder($this->tenant, $this->orderId, 'again')));
    }

    #[Test]
    public function cancellingANonexistentOrderIsRejected(): void
    {
        $decision = OrderRules::decide($this->emptyState(), new CancelOrder($this->tenant, $this->orderId, 'nope'));

        self::assertInstanceOf(Rejection::class, $decision);
    }

    #[Test]
    public function unknownCommandsAreRejected(): void
    {
        self::assertInstanceOf(Rejection::class, OrderRules::decide($this->emptyState(), new \stdClass()));
    }

    private function emptyState(): OrderState
    {
        return OrderState::empty($this->tenant, $this->orderId);
    }

    private function place(): PlaceOrder
    {
        return new PlaceOrder($this->tenant, $this->orderId, $this->lines);
    }

    private function placedEvent(): OrderPlaced
    {
        return new OrderPlaced($this->tenant, $this->orderId, $this->lines, Money::of(3998, 'EUR'));
    }

    private function cancelledState(): OrderState
    {
        $placed = OrderState::evolve($this->emptyState(), $this->placedEvent());

        return OrderState::evolve($placed, new OrderCancelled($this->tenant, $this->orderId, 'x'));
    }
}
```

`tests/Unit/Orders/Domain/OrderStateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Orders\Domain;

use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderState;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderState::class)]
final class OrderStateTest extends TestCase
{
    #[Test]
    public function startsNotCreatedAndFoldsThroughLifecycle(): void
    {
        $tenant = TenantId::fromString('acme');
        $id = OrderId::generate();
        $lines = [new OrderLine(Sku::fromString('A-1'), Quantity::of(1), Money::of(500, 'EUR'))];

        $state = OrderState::empty($tenant, $id);
        self::assertSame(OrderStatus::NotCreated, $state->status);
        self::assertNull($state->total);

        $state = OrderState::evolve($state, new OrderPlaced($tenant, $id, $lines, Money::of(500, 'EUR')));
        self::assertSame(OrderStatus::Placed, $state->status);
        self::assertTrue($state->total?->equals(Money::of(500, 'EUR')));

        $state = OrderState::evolve($state, new OrderCancelled($tenant, $id, 'why not'));
        self::assertSame(OrderStatus::Cancelled, $state->status);
        self::assertSame('why not', $state->cancelReason);
    }

    #[Test]
    public function unknownEventsLeaveStateUntouched(): void
    {
        $state = OrderState::empty(TenantId::fromString('acme'), OrderId::generate());

        self::assertSame($state, OrderState::evolve($state, new \stdClass()));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `make test`
Expected: FAIL — `Class "...\Orders\Domain\OrderRules" not found`.

- [ ] **Step 3: Implement the domain**

`src/SharedKernel/Contracts/Orders/OrderCancelled.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Serialization\MessageType;

/**
 * Published language: an order was cancelled (customer request, or —
 * in later milestones — saga compensation).
 */
#[MessageType('orders.order_cancelled.v1')]
final readonly class OrderCancelled
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public string $reason,
    ) {}
}
```

Add `OrderCancelled::class` to `MessageTypes::CONTRACTS` (alphabetical: OrderCancelled before OrderPlaced).

`src/Orders/Domain/OrderStatus.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

enum OrderStatus: string
{
    case NotCreated = 'not_created';
    case Placed = 'placed';
    case Cancelled = 'cancelled';
}
```

`src/Orders/Domain/Rejection.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

/**
 * A domain "no" — a first-class value, not an exception. Invalid commands
 * are expected business outcomes; exceptions are reserved for defects.
 */
final readonly class Rejection
{
    public function __construct(public string $reason) {}
}
```

`src/Orders/Domain/Command/PlaceOrder.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * The client supplies the OrderId (a ULID) — it doubles as the
 * idempotency key: retrying the same id is safe by construction.
 */
final readonly class PlaceOrder
{
    /**
     * @param non-empty-list<OrderLine> $lines
     */
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public array $lines,
    ) {}
}
```

`src/Orders/Domain/Command/CancelOrder.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

final readonly class CancelOrder
{
    public function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public string $reason,
    ) {}
}
```

`src/Orders/Domain/OrderState.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * EVOLVE: the fold of the event log. Pure — no clock, no I/O, no context.
 */
final readonly class OrderState
{
    /**
     * @param list<OrderLine> $lines
     */
    private function __construct(
        public TenantId $tenantId,
        public OrderId $orderId,
        public OrderStatus $status,
        public array $lines,
        public ?Money $total,
        public ?string $cancelReason,
    ) {}

    public static function empty(TenantId $tenantId, OrderId $orderId): self
    {
        return new self($tenantId, $orderId, OrderStatus::NotCreated, [], null, null);
    }

    public static function evolve(self $state, object $event): self
    {
        return match (true) {
            $event instanceof OrderPlaced => new self(
                $state->tenantId,
                $state->orderId,
                OrderStatus::Placed,
                $event->lines,
                $event->total,
                null,
            ),
            $event instanceof OrderCancelled => new self(
                $state->tenantId,
                $state->orderId,
                OrderStatus::Cancelled,
                $state->lines,
                $state->total,
                $event->reason,
            ),
            default => $state,
        };
    }
}
```

`src/Orders/Domain/OrderRules.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Domain;

use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;

use function array_reduce;

/**
 * DECIDE: command in, events (or a Rejection) out. Pure — the only place
 * order invariants live. An empty event list means "already done":
 * idempotent success.
 */
final class OrderRules
{
    /**
     * @return list<object>|Rejection
     */
    public static function decide(OrderState $state, object $command): array|Rejection
    {
        return match (true) {
            $command instanceof PlaceOrder => self::place($state, $command),
            $command instanceof CancelOrder => self::cancel($state, $command),
            default => new Rejection('Unknown command ' . $command::class),
        };
    }

    /**
     * @return list<object>|Rejection
     */
    private static function place(OrderState $state, PlaceOrder $command): array|Rejection
    {
        return match ($state->status) {
            OrderStatus::NotCreated => [
                new OrderPlaced($command->tenantId, $command->orderId, $command->lines, self::total($command->lines)),
            ],
            OrderStatus::Placed => [],
            OrderStatus::Cancelled => new Rejection('Order was cancelled; place a new order instead'),
        };
    }

    /**
     * @return list<object>|Rejection
     */
    private static function cancel(OrderState $state, CancelOrder $command): array|Rejection
    {
        return match ($state->status) {
            OrderStatus::NotCreated => new Rejection('Order does not exist'),
            OrderStatus::Placed => [new OrderCancelled($command->tenantId, $command->orderId, $command->reason)],
            OrderStatus::Cancelled => [],
        };
    }

    /**
     * @param non-empty-list<OrderLine> $lines
     */
    private static function total(array $lines): Money
    {
        return array_reduce(
            $lines,
            static fn(?Money $carry, OrderLine $line): Money => $carry === null
                ? $line->total()
                : $carry->add($line->total()),
            null,
        ) ?? throw new \LogicException('non-empty-list guarantees at least one line');
    }
}
```

(If Psalm level 1 objects to the `?? throw`, compute the first line's total then fold the rest — keep it pure either way.)

- [ ] **Step 4: Run tests to verify green, then commit**

Run: `make test`
Expected: PASS — 37 tests (28 + 7 rules + 2 state).

```bash
git add examples/nexus-fulfillment
git commit -m "feat(fulfillment): Orders domain — pure DECIDE/EVOLVE with idempotent place/cancel"
```

---

### Task 3: ContextBus — the cross-context fan-out seam (TDD)

**Files:**
- Create: `examples/nexus-fulfillment/src/Platform/Bus/Subscribe.php`
- Create: `examples/nexus-fulfillment/src/Platform/Bus/Publish.php`
- Create: `examples/nexus-fulfillment/src/Platform/Bus/ContextBusActor.php`
- Test: `examples/nexus-fulfillment/tests/Integration/Platform/Bus/ContextBusTest.php`
- Modify: `examples/nexus-fulfillment/phpunit.xml` (add `integration` testsuite for `tests/Integration`)

**Interfaces:**
- Consumes: `Behavior::withState`, `BehaviorWithState` (nexus-core), FiberRuntime (tests).
- Produces (Tasks 4–5 and every later milestone rely on these):
  - `Subscribe::__construct(public ActorRef $subscriber)` — subscriber receives every published event as a plain `tell($event)`. `readonly`.
  - `Publish::__construct(public object $event)` — `readonly`.
  - `ContextBusActor::behavior(): Behavior` — stateful behavior holding `list<ActorRef>` subscribers; `Subscribe` appends, `Publish` fans out, anything else is unhandled.
  - Registered under the actor name **`context-bus`** (Task 6 wires it).

There is no existing bus in Nexus — this is deliberately a ~40-line actor, the seam the Messenger bridge later replaces (spec §SharedKernel).

- [ ] **Step 1: Add the integration testsuite**

In `phpunit.xml`, inside `<testsuites>`, after the `unit` suite:

```xml
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
```

- [ ] **Step 2: Write the failing bus test**

Follow the Fiber integration pattern (spawn, tell, `scheduleOnce` shutdown, `run()`, assert). Model the harness on any test in `tests/Integration/Fiber/` at the monorepo root (read one first for the exact FiberRuntime + shutdown idiom).

`tests/Integration/Platform/Bus/ContextBusTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Platform\Bus;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Subscribe;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContextBusActor::class)]
final class ContextBusTest extends TestCase
{
    #[Test]
    public function publishedEventsFanOutToAllSubscribers(): void
    {
        $received = [];
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('bus-test', $runtime);

        $probe = static function (string $key) use (&$received): Behavior {
            return Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$received, $key): Behavior {
                $received[$key][] = $msg::class;

                return Behavior::same();
            });
        };

        $bus = $system->spawn(Props::fromBehavior(ContextBusActor::behavior()), 'context-bus');
        $a = $system->spawn(Props::fromBehavior($probe('a')), 'probe-a');
        $b = $system->spawn(Props::fromBehavior($probe('b')), 'probe-b');

        $bus->tell(new Subscribe($a));
        $bus->tell(new Subscribe($b));
        $bus->tell(new Publish(new FakeEvent()));

        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertSame([FakeEvent::class], $received['a'] ?? []);
        self::assertSame([FakeEvent::class], $received['b'] ?? []);
    }
}

final readonly class FakeEvent {}
```

Adapt imports/idioms to what the monorepo's Fiber integration tests actually use (that's the reference — adjust and note it).

- [ ] **Step 3: Run to verify failure, implement, run to green**

Run: `make test` → FAIL (ContextBusActor not found).

`src/Platform/Bus/Subscribe.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Bus;

use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * Register a subscriber. Subscribers receive every published event via a
 * plain tell($event) and filter for themselves — topics arrive when a
 * second context needs them (YAGNI).
 *
 * @psalm-suppress UntypedActorRefInjection -- the bus is intentionally
 * heterogeneous; see psalm.xml if the plugin flags this instead.
 */
final readonly class Subscribe
{
    /**
     * @param ActorRef<object> $subscriber
     */
    public function __construct(public ActorRef $subscriber) {}
}
```

(If the nexus-psalm `UntypedActorRefInjection` rule fires and the inline suppress doesn't take, add a file-scoped `issueHandlers` exemption in `psalm.xml` for `src/Platform/Bus/` with a comment — mirroring the monorepo's own by-design exemptions. Record which mechanism you used.)

`src/Platform/Bus/Publish.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Bus;

final readonly class Publish
{
    public function __construct(public object $event) {}
}
```

`src/Platform/Bus/ContextBusActor.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Bus;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;

/**
 * In-process fan-out between bounded contexts — the seam a broker
 * (Messenger bridge) replaces when contexts split into services.
 * Delivery is at-most-once, in-process, fire-and-forget by design.
 */
final class ContextBusActor
{
    public static function behavior(): Behavior
    {
        return Behavior::withState(
            [],
            static function (ActorContext $ctx, object $msg, array $subscribers): BehaviorWithState {
                if ($msg instanceof Subscribe) {
                    $subscribers[] = $msg->subscriber;

                    return BehaviorWithState::next($subscribers);
                }

                if ($msg instanceof Publish) {
                    foreach ($subscribers as $subscriber) {
                        $subscriber->tell($msg->event);
                    }

                    return BehaviorWithState::same();
                }

                return BehaviorWithState::same();
            },
        );
    }
}
```

(Adjust the exact `Behavior::withState`/`BehaviorWithState` callable signature to nexus-core's real API — check `packages/nexus-core/src/Actor/Behavior.php` — and note adaptations.)

Run: `make test` → PASS — 38 tests.

- [ ] **Step 4: Commit**

```bash
git add examples/nexus-fulfillment
git commit -m "feat(fulfillment): ContextBus — in-process cross-context event fan-out"
```

---

### Task 4: OrderActor — event-sourced entity with passivation (TDD)

**Files:**
- Create: `examples/nexus-fulfillment/src/Orders/Application/OrderActor.php`
- Create: `examples/nexus-fulfillment/src/Orders/Application/OrderRefFactory.php`
- Create: `examples/nexus-fulfillment/src/Orders/Application/Reply/OrderAccepted.php`
- Create: `examples/nexus-fulfillment/src/Orders/Application/Reply/OrderRejected.php`
- Test: `examples/nexus-fulfillment/tests/Integration/Orders/OrderActorTest.php`

**Interfaces:**
- Consumes: Task 2 domain; Task 3 bus (`Publish`); `EventSourcedBehavior`, `Effect`, `PersistenceId`, `SnapshotStrategy`, `RetentionPolicy` (nexus-persistence); `InMemoryEventStore` (tests — find its FQCN in nexus-persistence, wallet-app uses it); `ActorContext::{sender,setReceiveTimeout}`; `ReceiveTimeout` signal.
- Produces (Task 6 relies on):
  - `OrderActor::behavior(TenantId, OrderId, EventStore $store, SnapshotStore $snapshots, ActorRef $bus, Duration $passivateAfter): Behavior`
  - `OrderRefFactory::__construct(ActorSystem, EventStore, SnapshotStore, ActorRef $bus, Duration $passivateAfter)`; `of(TenantId, OrderId): ActorRef` — actor name `order-{tenant}|{orderId}`.
  - `OrderAccepted::__construct(public OrderId $orderId, public OrderStatus $status, public ?Money $total)`; `OrderRejected::__construct(public OrderId $orderId, public string $reason)`. Both `final readonly`.
  - PersistenceId convention: `PersistenceId::of('Order', "{tenant}|{orderId}")`.

- [ ] **Step 1: Read the references**

Before writing: `examples/nexus-wallet-app/src/Actor/WalletActor.php` end-to-end (EventSourcedBehavior wiring, the exact sender-capture idiom used to reply from the command handler, Effect::persist→thenRun/thenReply usage), `examples/nexus-tictactoe/src/Actor/GameRefFactory.php` (cache + isAlive + spawn), and `packages/nexus-doctrine-orm/src/Behavior/EntityBehaviorRunner.php:120-140` (the ReceiveTimeout→`Behavior::stopped()` onSignal idiom). Mirror those idioms exactly; the code below is the intended shape, not gospel.

- [ ] **Step 2: Write the failing actor tests**

`tests/Integration/Orders/OrderActorTest.php` — four behaviors, Fiber runtime, in-memory stores:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Integration\Orders;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\CancelOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\ContextBusActor;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OrderRefFactory::class)]
final class OrderActorTest extends TestCase
{
    #[Test]
    public function placeThenGetIsAcceptedAndCancelWorks(): void
    {
        // spawn factory with InMemoryEventStore/-SnapshotStore + a bus,
        // inside the runtime: ask(PlaceOrder)->await() → OrderAccepted{status: Placed, total: 3998},
        // ask(same PlaceOrder)->await() → OrderAccepted again (idempotent),
        // ask(CancelOrder)->await() → OrderAccepted{status: Cancelled},
        // ask(PlaceOrder)->await() after cancel → OrderRejected.
    }

    #[Test]
    public function stateRecoversAfterEntityStopsViaReplay(): void
    {
        // place order; stop the entity (system->stop or passivation);
        // re-acquire via factory->of() — same store; ask CancelOrder;
        // expect OrderAccepted (state was Placed only if replay worked).
    }

    #[Test]
    public function idleEntityPassivatesAfterReceiveTimeout(): void
    {
        // passivateAfter = millis(100); place; wait 300ms via scheduleOnce;
        // assert ref->isAlive() === false.
    }

    #[Test]
    public function persistedEventsArePublishedToTheBus(): void
    {
        // subscribe a probe to the bus; place an order;
        // assert probe received one OrderPlaced.
    }
}
```

Write these as REAL tests (the comments above are the scenario spec — the harness idiom comes from the monorepo's `tests/Integration/Fiber/` tests you read in Task 3; asks must run inside the runtime, e.g. driven from a `scheduleOnce` callback or a driver actor, matching how wallet-app's own integration tests drive ask()). Run `make test` → FAIL (classes not found).

- [ ] **Step 3: Implement replies, actor, factory**

`Reply/OrderAccepted.php` and `Reply/OrderRejected.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply;

use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderStatus;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;

final readonly class OrderAccepted
{
    public function __construct(
        public OrderId $orderId,
        public OrderStatus $status,
        public ?Money $total,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply;

use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;

final readonly class OrderRejected
{
    public function __construct(
        public OrderId $orderId,
        public string $reason,
    ) {}
}
```

`src/Orders/Application/OrderActor.php` — intended shape (mirror WalletActor's real idioms):

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderRules;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\OrderState;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Rejection;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\RetentionPolicy;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\Store\EventStore;
use Monadial\Nexus\Persistence\Store\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * The Order entity's actor shell. All decisions live in OrderRules
 * (Domain); this class only wires persistence, replies, publication,
 * and passivation.
 */
final class OrderActor
{
    /**
     * @param ActorRef<Publish> $bus
     */
    public static function behavior(
        TenantId $tenantId,
        OrderId $orderId,
        EventStore $store,
        SnapshotStore $snapshots,
        ActorRef $bus,
        Duration $passivateAfter,
    ): Behavior {
        $es = EventSourcedBehavior::create(
            PersistenceId::of('Order', "{$tenantId->value}|{$orderId->value}"),
            OrderState::empty($tenantId, $orderId),
            static function (OrderState $state, ActorContext $ctx, object $command) use ($bus): Effect {
                $sender = $ctx->sender();   // mirror WalletActor's exact idiom for extracting the reply target

                $decision = OrderRules::decide($state, $command);

                if ($decision instanceof Rejection) {
                    // reply without persisting — mirror WalletActor's rejection reply idiom
                    return Effect::none()->thenRun(
                        static fn(OrderState $s) => /* tell sender OrderRejected($s->orderId, $decision->reason) */ null,
                    );
                }

                if ($decision === []) {
                    // idempotent success — reply current state, persist nothing
                    return Effect::none()->thenRun(
                        static fn(OrderState $s) => /* tell sender OrderAccepted($s->orderId, $s->status, $s->total) */ null,
                    );
                }

                return Effect::persist(...$decision)->thenRun(
                    static function (OrderState $next) use ($bus, $decision /* , $sender */): void {
                        foreach ($decision as $event) {
                            $bus->tell(new Publish($event));
                        }
                        // tell sender OrderAccepted($next->orderId, $next->status, $next->total)
                    },
                );
            },
            static fn(OrderState $state, object $event): OrderState => OrderState::evolve($state, $event),
        )
            ->withEventStore($store)
            ->withSnapshotStore($snapshots)
            ->withSnapshotStrategy(SnapshotStrategy::everyN(50))
            ->withRetention(RetentionPolicy::snapshotAndEvents(3, deleteEventsTo: false))
            ->toBehavior();

        return Behavior::setup(static function (ActorContext $ctx) use ($es, $passivateAfter): Behavior {
            $ctx->setReceiveTimeout($passivateAfter);

            return $es;
        })->onSignal(static function (ActorContext $ctx, object $signal): Behavior {
            if ($signal instanceof ReceiveTimeout) {
                return Behavior::stopped();
            }

            return Behavior::same();
        });
    }
}
```

IMPORTANT for the implementer: the commented `tell sender` spots and the `$ctx->sender()` line are where you must transplant WalletActor's REAL reply idiom (how it turns `sender()` into a tellable ref and replies from `thenRun` — including whether `Effect::none()->thenRun(...)` is legal or whether rejection replies use `Effect::reply($replyTo, ...)` with a ref extracted before the match). Whether `->onSignal()` attaches to the setup wrapper or must wrap the inner behavior — check `Behavior::setup`'s API in nexus-core. Also verify `EventStore`/`SnapshotStore` interface FQCNs and `InMemoryEventStore`/`InMemorySnapshotStore` FQCNs from nexus-persistence. Get it compiling against reality, keep the structure, note every adaptation in your report.

`src/Orders/Application/OrderRefFactory.php` — GameRefFactory pattern:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Application;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\Fulfillment\Platform\Bus\Publish;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Persistence\Store\EventStore;
use Monadial\Nexus\Persistence\Store\SnapshotStore;
use Monadial\Nexus\Runtime\Duration;

/**
 * Spawn-on-demand entity access: a dead or never-spawned order actor is
 * (re)spawned and the persistence engine replays its events. Callers
 * never know whether the entity was already in memory.
 */
final class OrderRefFactory
{
    /** @var array<string, ActorRef> */
    private array $cache = [];

    /**
     * @param ActorRef<Publish> $bus
     */
    public function __construct(
        private readonly ActorSystem $system,
        private readonly EventStore $store,
        private readonly SnapshotStore $snapshots,
        private readonly ActorRef $bus,
        private readonly Duration $passivateAfter,
    ) {}

    public function of(TenantId $tenantId, OrderId $orderId): ActorRef
    {
        $name = "order-{$tenantId->value}|{$orderId->value}";

        if (isset($this->cache[$name]) && $this->cache[$name]->isAlive()) {
            return $this->cache[$name];
        }

        unset($this->cache[$name]);

        $ref = $this->system->spawn(
            Props::fromBehavior(OrderActor::behavior(
                $tenantId,
                $orderId,
                $this->store,
                $this->snapshots,
                $this->bus,
                $this->passivateAfter,
            )),
            $name,
        );

        return $this->cache[$name] = $ref;
    }
}
```

- [ ] **Step 4: Run to green, commit**

Run: `make test`
Expected: PASS — 42 tests (38 + 4). Test output pristine.

```bash
git add examples/nexus-fulfillment
git commit -m "feat(fulfillment): OrderActor — event-sourced entity with passivation and bus publication"
```

---

### Task 5: orders_view read model + projector actor (TDD)

**Files:**
- Create: `examples/nexus-fulfillment/src/Orders/Infrastructure/ReadModel/OrderView.php`
- Create: `examples/nexus-fulfillment/src/Orders/Infrastructure/ReadModel/OrdersReadModel.php`
- Create: `examples/nexus-fulfillment/src/Orders/Infrastructure/ReadModel/OrdersViewProjector.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Boot/DoctrineKit.php` (add entity path)
- Test: `examples/nexus-fulfillment/tests/Integration/Orders/OrdersProjectionTest.php`

**Interfaces:**
- Consumes: Task 3 bus, contracts, `EntityManagerPool::withEntityManager(Closure)`.
- Produces (Task 6 relies on):
  - `OrderView` — Doctrine entity, table `orders_view`, columns: `id` (string PK), `tenant_id` (string, indexed with status), `status` (string), `total_amount` (int), `currency` (string(3)), `line_count` (int), `cancel_reason` (string nullable), `updated_at` (datetime_immutable). Getters for all; constructor `__construct(string $id, string $tenantId)`; `applyPlaced(OrderPlaced): void`, `applyCancelled(OrderCancelled): void`.
  - `OrdersReadModel::__construct(EntityManagerPool $pool)`; `apply(object $event): void` (upsert); used by the projector only. Queries go through the request-scoped `EntityManagerInterface` in handlers (CQRS read side), NOT through this class.
  - `OrdersViewProjector::behavior(OrdersReadModel): Behavior` — subscribes itself to nothing (Task 6 sends `Subscribe`); handles `OrderPlaced`/`OrderCancelled` by calling `$readModel->apply($event)`, ignores everything else. Registered under actor name **`orders-projector`**.

Read model maintenance is a **projector actor consuming the bus** (spec) — NOT tictactoe's inline `thenRun` projection; the write side already publishes to the bus, so the projector is just another subscriber. `DoctrineGameReadModel` (`examples/nexus-tictactoe/src/ReadModel/DoctrineGameReadModel.php`) is the reference for the pool-backed upsert.

- [ ] **Step 1: Write the failing projection test**

`tests/Integration/Orders/OrdersProjectionTest.php` — Fiber runtime; build a real `EntityManagerPool` over **pdo_sqlite in-memory** (mirror how monorepo Doctrine tests construct sqlite pools — search `tests/Integration` and nexus-doctrine-orm tests for the sqlite params idiom); SchemaTool-create `orders_view`; spawn bus + projector, `Subscribe` the projector, `Publish` an `OrderPlaced` then `OrderCancelled`; after shutdown assert via a plain EM: one row, status `cancelled`, total 3998, line_count 1, cancel_reason set. Write it as a real test following the harness idioms from Tasks 3–4.

Run: `make test` → FAIL.

- [ ] **Step 2: Implement OrderView, OrdersReadModel, OrdersViewProjector**

`OrderView.php` — Doctrine attributes (mirror `GameSession`'s style):

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;

use function count;

/**
 * CQRS read side: a flat, indexed row per order. Derived state — the
 * event journal is the source of truth; this table is rebuildable.
 */
#[Entity]
#[Table(name: 'orders_view')]
#[Index(columns: ['tenant_id', 'status'])]
final class OrderView
{
    #[Id]
    #[Column]
    private string $id;

    #[Column(name: 'tenant_id')]
    private string $tenantId;

    #[Column]
    private string $status;

    #[Column(name: 'total_amount')]
    private int $totalAmount = 0;

    #[Column(length: 3)]
    private string $currency = 'EUR';

    #[Column(name: 'line_count')]
    private int $lineCount = 0;

    #[Column(name: 'cancel_reason', nullable: true)]
    private ?string $cancelReason = null;

    #[Column(name: 'updated_at')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $tenantId)
    {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->status = 'placed';
        $this->updatedAt = new DateTimeImmutable();
    }

    public function applyPlaced(OrderPlaced $event): void
    {
        $this->status = 'placed';
        $this->totalAmount = $event->total->amount;
        $this->currency = $event->total->currency;
        $this->lineCount = count($event->lines);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function applyCancelled(OrderCancelled $event): void
    {
        $this->status = 'cancelled';
        $this->cancelReason = $event->reason;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function lineCount(): int
    {
        return $this->lineCount;
    }

    public function cancelReason(): ?string
    {
        return $this->cancelReason;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
```

`OrdersReadModel.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;

/**
 * Write side of the read model: one pooled-EM upsert per event.
 */
final readonly class OrdersReadModel
{
    public function __construct(private EntityManagerPool $pool) {}

    public function apply(object $event): void
    {
        if ($event instanceof OrderPlaced) {
            $this->pool->withEntityManager(function (EntityManagerInterface $em) use ($event): void {
                $row = $em->find(OrderView::class, $event->orderId->value)
                    ?? new OrderView($event->orderId->value, $event->tenantId->value);
                $row->applyPlaced($event);
                $em->persist($row);
                $em->flush();
            });

            return;
        }

        if ($event instanceof OrderCancelled) {
            $this->pool->withEntityManager(function (EntityManagerInterface $em) use ($event): void {
                $row = $em->find(OrderView::class, $event->orderId->value);

                if ($row === null) {
                    return;
                }

                $row->applyCancelled($event);
                $em->persist($row);
                $em->flush();
            });
        }
    }
}
```

(Verify `EntityManagerPool` FQCN — research says `Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool` with `withEntityManager(Closure)`; adapt to reality.)

`OrdersViewProjector.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\ReadModel;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderCancelled;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;

/**
 * One projector actor per read model (spec): consumes the ContextBus,
 * folds Orders events into orders_view. Restart-safe — upserts are
 * idempotent per event.
 */
final class OrdersViewProjector
{
    public static function behavior(OrdersReadModel $readModel): Behavior
    {
        return Behavior::receive(static function (ActorContext $ctx, object $event) use ($readModel): Behavior {
            if ($event instanceof OrderPlaced || $event instanceof OrderCancelled) {
                $readModel->apply($event);
            }

            return Behavior::same();
        });
    }
}
```

Add `src/Orders/Infrastructure/ReadModel` to `DoctrineKit`'s ORM entity-paths list.

- [ ] **Step 3: Run to green, commit**

Run: `make test` → PASS — 43 tests.

```bash
git add examples/nexus-fulfillment
git commit -m "feat(fulfillment): orders_view read model via bus-fed projector actor"
```

---

### Task 6: HTTP vertical — auth, handlers, wiring, live verification

**Files:**
- Create: `examples/nexus-fulfillment/src/Platform/Http/Auth/DemoTokens.php`
- Create: `examples/nexus-fulfillment/src/Orders/Infrastructure/Http/PlaceOrderRequest.php`
- Create: `examples/nexus-fulfillment/src/Orders/Infrastructure/Http/PlaceOrderHandler.php`
- Create: `examples/nexus-fulfillment/src/Orders/Infrastructure/Http/ListOrdersHandler.php`
- Create: `examples/nexus-fulfillment/src/Orders/Infrastructure/Http/GetOrderHandler.php`
- Create: `examples/nexus-fulfillment/src/Orders/Infrastructure/Http/CancelOrderHandler.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Http/Routes.php`
- Modify: `examples/nexus-fulfillment/src/Platform/Boot/App.php`
- Modify: `examples/nexus-fulfillment/README.md` (status + tokens + API examples)
- Test: `examples/nexus-fulfillment/tests/Unit/Platform/Http/Auth/DemoTokensTest.php`

**Interfaces:**
- Consumes: everything above; nexus-http-auth (`AuthenticationMiddleware`, `StaticTokenAuthenticator`, `SimplePrincipal`, `FromPrincipalResolver`, `#[FromPrincipal]`); nexus-http (`#[FromBody]`, `JsonResponse`, `Response`, path params); doctrine middlewares/resolvers (`ConnectionScopeMiddleware`, `EntityManagerScopeMiddleware`, `PoolExhaustedToServiceUnavailable`, `ConnectionResolver`, `EntityManagerResolver`); `ask()->await()`.
- Produces: the working API of milestone 2:
  - `POST /api/orders` (role `ops`) — body `{"orderId": "<ULID>", "lines": [{"sku": "...", "quantity": n, "unitPriceCents": n, "currency": "EUR"}]}` → 201 accepted / 409 rejected / 400 invalid. **Retrying the same orderId returns the same 201 result.**
  - `GET /api/orders` (role `ops`) — tenant-scoped list from `orders_view`.
  - `GET /api/orders/{id}` (role `ops`) — one row or 404.
  - `DELETE /api/orders/{id}` (role `ops`) — cancel → 200 / 409 / 404-equivalent rejection.
  - Demo tokens (hardcoded, documented): `acme-ops-token` → SimplePrincipal(id `ops@acme`, roles `['ops']`, claims `['tenant' => 'acme']`); `acme-picker-token` → roles `['picker']`, tenant `acme`; `umbrella-ops-token` → roles `['ops']`, tenant `umbrella`.

- [ ] **Step 1: DemoTokens (TDD — small)**

Failing test `tests/Unit/Platform/Http/Auth/DemoTokensTest.php`: `DemoTokens::authenticator()` returns a `StaticTokenAuthenticator`; build a PSR-7 request with `Authorization: Bearer acme-ops-token` (Nyholm) and assert the resulting principal has id `ops@acme`, role `ops`, claim `tenant => acme`; assert `umbrella-ops-token` maps to tenant `umbrella`; unknown token → null.

Then implement `src/Platform/Http/Auth/DemoTokens.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http\Auth;

use Monadial\Nexus\Http\Auth\Authenticator\StaticTokenAuthenticator;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;

/**
 * Fixture identities for the example. Three tokens, two tenants, two
 * roles — enough to demonstrate tenant isolation and role guards.
 * Real deployments replace this with a JWT/OIDC Authenticator.
 */
final class DemoTokens
{
    public static function authenticator(): StaticTokenAuthenticator
    {
        return new StaticTokenAuthenticator([
            'acme-ops-token' => new SimplePrincipal(
                id: 'ops@acme',
                roles: ['ops'],
                scopes: [],
                claims: ['tenant' => 'acme'],
            ),
            'acme-picker-token' => new SimplePrincipal(
                id: 'picker@acme',
                roles: ['picker'],
                scopes: [],
                claims: ['tenant' => 'acme'],
            ),
            'umbrella-ops-token' => new SimplePrincipal(
                id: 'ops@umbrella',
                roles: ['ops'],
                scopes: [],
                claims: ['tenant' => 'umbrella'],
            ),
        ]);
    }
}
```

Run: `make test` → green (43 + however many DemoTokens test methods you wrote). Commit: `git commit -m "feat(fulfillment): demo bearer tokens with tenant claims"`.

- [ ] **Step 2: Request DTO and handlers**

`PlaceOrderRequest.php` — flat primitives (friendly JSON API; VOs constructed in the handler where validation errors map to 400):

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

/**
 * @psalm-type LineShape = array{currency: string, quantity: int, sku: string, unitPriceCents: int}
 */
final readonly class PlaceOrderRequest
{
    /**
     * @param non-empty-list<PlaceOrderLine> $lines
     */
    public function __construct(
        public string $orderId,
        public array $lines,
    ) {}
}
```

plus `PlaceOrderLine` in the same directory:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

final readonly class PlaceOrderLine
{
    public function __construct(
        public string $sku,
        public int $quantity,
        public int $unitPriceCents,
        public string $currency,
    ) {}
}
```

`PlaceOrderHandler.php` — constructor DI for the factory (LedgerRecordHandler precedent), request-bound params on `__invoke`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Orders\Infrastructure\Http;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\OrderRefFactory;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderAccepted;
use Monadial\Nexus\Example\Fulfillment\Orders\Application\Reply\OrderRejected;
use Monadial\Nexus\Example\Fulfillment\Orders\Domain\Command\PlaceOrder;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Message\ResponseInterface;

final readonly class PlaceOrderHandler
{
    public function __construct(private OrderRefFactory $orders) {}

    public function __invoke(
        #[FromPrincipal] Principal $principal,
        #[FromBody] PlaceOrderRequest $body,
    ): ResponseInterface {
        if (!$principal->hasRole('ops')) {
            return Response::badRequest('role ops required'); // replace with the 403 helper if Response has one — check Response.php
        }

        try {
            $tenant = TenantId::fromString((string) ($principal->claims()['tenant'] ?? ''));
            $orderId = OrderId::fromString($body->orderId);
            $lines = array_map(
                static fn(PlaceOrderLine $l): OrderLine => new OrderLine(
                    Sku::fromString($l->sku),
                    Quantity::of($l->quantity),
                    Money::of($l->unitPriceCents, $l->currency),
                ),
                $body->lines,
            );
        } catch (InvalidArgumentException $e) {
            return Response::badRequest($e->getMessage());
        }

        $reply = $this->orders->of($tenant, $orderId)
            ->ask(new PlaceOrder($tenant, $orderId, $lines), Duration::seconds(2))
            ->await();

        return match (true) {
            $reply instanceof OrderAccepted => JsonResponse::created([
                'orderId' => $reply->orderId->value,
                'status' => $reply->status->value,
                'totalCents' => $reply->total?->amount,
            ], null),
            $reply instanceof OrderRejected => new \Nyholm\Psr7\Response(
                409,
                ['content-type' => 'application/json'],
                (string) json_encode(['orderId' => $reply->orderId->value, 'reason' => $reply->reason]),
            ),
            default => Response::internalServerError(),
        };
    }
}
```

(Check `Response.php` for a `forbidden()` helper; use it if present. Note: array keys in the 409 body must be alphabetical — `orderId` before `reason`. Fix `JsonResponse::created` signature to reality.)

`ListOrdersHandler.php` / `GetOrderHandler.php` / `CancelOrderHandler.php` — same idioms:

- `ListOrdersHandler::__invoke(#[FromPrincipal] Principal, EntityManagerInterface $em)`: role guard; `$em->getRepository(OrderView::class)->findBy(['tenantId' => $tenant->value], ['updatedAt' => 'DESC'], 100)`; map rows to `['currency', 'lineCount', 'orderId', 'status', 'totalCents', 'updatedAt']` arrays (alphabetical keys); `JsonResponse::ok(['orders' => $rows])`.
- `GetOrderHandler::__invoke(#[FromPrincipal] Principal, string $id, EntityManagerInterface $em)`: find by PK, verify `tenantId` matches principal's tenant (404 if not — don't leak other tenants' ids), 404 via `Response::notFound('order not found')`, else `JsonResponse::ok(...)` with the same row shape + `cancelReason`.
- `CancelOrderHandler::__construct(OrderRefFactory)`; `__invoke(#[FromPrincipal] Principal, string $id)`: role guard; validate `$id` as OrderId (400); `ask(new CancelOrder(...('api cancel')), Duration::seconds(2))->await()`; OrderAccepted → `JsonResponse::ok([...])`, OrderRejected → 409 (same shape as PlaceOrderHandler's 409); note a cancel of a nonexistent order surfaces as 409 with reason "Order does not exist" — acceptable for v1, document in README.

Write all three fully — no stubs.

- [ ] **Step 3: Wire Routes and App**

`Routes::register` signature grows: `register(WsApplication $app, ReadinessProbe $probe, OrderRefFactory $orders): void` — add:

```php
        $app->post('/api/orders', new PlaceOrderHandler($orders)(...));
        $app->get('/api/orders', ListOrdersHandler::class);
        $app->get('/api/orders/{id}', GetOrderHandler::class);
        $app->delete('/api/orders/{id}', new CancelOrderHandler($orders)(...));
```

`App::factory` additions, in order (mirror WalletApp/tictactoe ordering):

```php
$app->withMessageSerializer(new ValinorJsonSerializer());
$app->middleware(new AuthenticationMiddleware(DemoTokens::authenticator(), $log));
$app->middleware(new ConnectionScopeMiddleware($doctrine->connPool));
$app->middleware(new EntityManagerScopeMiddleware($doctrine->emPool));
$app->middleware(new PoolExhaustedToServiceUnavailable(new Psr17Factory()));
$app->paramResolver(new FromPrincipalResolver());
$app->paramResolver(new ConnectionResolver());
$app->paramResolver(new EntityManagerResolver());

$bus = $app->actor('context-bus', Props::fromBehavior(ContextBusActor::behavior()));
$projector = $app->actor('orders-projector', Props::fromBehavior(
    OrdersViewProjector::behavior(new OrdersReadModel($doctrine->emPool)),
));
// subscribe projector to bus + build OrderRefFactory with the bus ref —
// ActorRegistration exposes the ref (check HttpApplication::actor()'s return
// type for how to get the ActorRef out; mirror how wallet-app/tictactoe get
// refs to registered actors, e.g. via ActorRegistration or system lookup).
```

The exact mechanics of getting an `ActorRef` from `$app->actor(...)` registration and sending the initial `Subscribe` must be mirrored from how the examples do startup wiring (tictactoe builds `GameRefFactory` with the system directly in `DoctrineKit`; if `$app->actor()` registration doesn't expose refs cleanly, spawn `context-bus` and `orders-projector` directly on `$system` before `WsApplication::create` and pass refs around — that is exactly what tictactoe does with its factory. Prefer the simplest working wiring and record it.)

`OrderRefFactory` construction: `new OrderRefFactory($system, $doctrine->eventStore, $doctrine->snapshotStore, $busRef, Duration::minutes(5))`.

- [ ] **Step 4: Full-suite + live end-to-end verification**

```bash
make test          # all suites green
make up && sleep 5

TOKEN="Authorization: Bearer acme-ops-token"
ULID=01K1B2C3D4E5F6G7H8J9K0M1N2   # any valid ULID; reuse it for the idempotency check

# place
curl -fsS -X POST -H "$TOKEN" -H 'Content-Type: application/json' \
  -d '{"orderId":"'$ULID'","lines":[{"sku":"WIDGET-42","quantity":2,"unitPriceCents":1999,"currency":"EUR"}]}' \
  http://localhost:9090/api/orders
# → 201 {"orderId":"...","status":"placed","totalCents":3998}

# idempotent retry — same command, same result, no duplicate
curl -fsS -X POST -H "$TOKEN" -H 'Content-Type: application/json' \
  -d '{"orderId":"'$ULID'","lines":[{"sku":"WIDGET-42","quantity":2,"unitPriceCents":1999,"currency":"EUR"}]}' \
  http://localhost:9090/api/orders

# read model
curl -fsS -H "$TOKEN" http://localhost:9090/api/orders
curl -fsS -H "$TOKEN" http://localhost:9090/api/orders/$ULID

# tenant isolation: umbrella sees nothing
curl -fsS -H "Authorization: Bearer umbrella-ops-token" http://localhost:9090/api/orders
# → {"orders":[]}

# cancel + verify
curl -fsS -X DELETE -H "$TOKEN" http://localhost:9090/api/orders/$ULID
curl -fsS -H "$TOKEN" http://localhost:9090/api/orders/$ULID   # status cancelled

# unauthenticated → 401
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:9090/api/orders

# event journal is the source of truth
docker compose exec db psql -U fulfillment -d fulfillment -c "select persistence_id, sequence_nr, event_type from nexus_event_journal;"
# → two rows for Order|acme|<ULID>: orders.order_placed.v1, orders.order_cancelled.v1
#   (table/column names per what Task 1 Step 5 recorded)

make down
```

Every expected output must be captured in your report. The journal query proving **wire-named Valinor-serialized events in Postgres** is the money shot of this milestone.

- [ ] **Step 5: README update**

Update status header to milestone 2, add an "API" section with the tokens table and the curl examples above, note the client-supplied-ULID idempotency model and the 409-on-unknown-cancel caveat.

- [ ] **Step 6: Full battery + commit**

Run: `make ci`
Expected: all suites + all four gates green. Fix at the source anything the gates raise (new files must pass Slevomat/Psalm level 1 + plugin — remember: readonly messages, alphabetical string-key arrays in every response body).

```bash
git add examples/nexus-fulfillment
git commit -m "feat(fulfillment): orders HTTP vertical — auth, idempotent place/cancel, tenant-scoped reads"
```

---

## Done means

- `make up` from clean: place → list → get → cancel → get works end-to-end over authenticated HTTP against Postgres, with tenant isolation and 401s verified live.
- Retrying `POST /api/orders` with the same `orderId` is observably idempotent (same 201, one journal entry set, one `orders_view` row).
- `nexus_event_journal` rows carry `orders.order_placed.v1` / `orders.order_cancelled.v1` type names with Valinor JSON payloads.
- Killing an order entity (passivation or restart) loses nothing — replay recovers state (proven by the actor test and by cancel-after-passivation working live).
- `make ci` green; Deptrac still 0 violations with the new `Orders/{Domain,Application,Infrastructure}` layers exercised for real (Domain imports only SharedKernel — the layer rules now bite).
- Milestone 3 (Inventory + saga) can start by adding `src/Inventory` + a `FulfillmentProcess` subscribing to the same ContextBus.
