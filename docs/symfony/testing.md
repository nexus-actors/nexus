# Testing

This guide covers all aspects of testing Symfony applications that use nexus-symfony: actor behavior in isolation, controller and service integration through the kernel pool, and end-to-end HTTP tests.

---

## Testing philosophy

A nexus-symfony application has three distinct layers, each with its own test strategy:

1. **Actor behavior** — pure logic inside `handle()` methods. Actors receive messages and return `Behavior` values. No HTTP, no Symfony kernel. These tests are fast, deterministic, and require no infrastructure.
2. **Controller and service integration** — Symfony kernel boots with actors wired up. Tests verify that controllers correctly call `tell()` or `ask()`, that messages are typed correctly, and that responses are shaped properly. Actors can be replaced with stubs.
3. **End-to-end HTTP tests** — Full Symfony `WebTestCase`. The kernel boots normally. The test sends HTTP requests and asserts on responses. Actors run synchronously via a test runtime.

Actors are easy to test in isolation because the actor model enforces pure message passing. A `handle()` method receives a message object and a context; it returns a `Behavior` value. There are no global side effects, no shared mutable state between actors, and no implicit I/O. To test an actor, spawn it in a test runtime, send messages, run the system, and assert on captured replies. No mocking framework is needed.

---

## Unit testing actors with FiberRuntime

The simplest integration test approach is `FiberRuntime`. It executes actors in real PHP Fibers. Tests run without Swoole and without the Symfony kernel.

### Setup

```php
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Core\Actor\ActorSystem;
```

`FiberRuntime` is in `monadial/nexus-runtime-fiber`, which is a transitive dependency of `monadial/nexus-symfony`.

### Testing a stateless actor

```php
<?php

declare(strict_types=1);

namespace App\Tests\Actor;

use App\Actor\GreeterActor;
use App\Actor\Message\Greet;
use App\Actor\Message\Greeted;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GreeterActorTest extends TestCase
{
    #[Test]
    public function greet_replies_with_greeting(): void
    {
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        $captured = null;

        $probeBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
                $captured = $msg;

                return Behavior::same();
            },
        );

        $probe    = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');
        $greeter  = $system->spawn(Props::fromFactory(fn() => new GreeterActor()), 'greeter');

        $greeter->tell(new Greet('Alice', $probe));

        $runtime->scheduleOnce(Duration::millis(200), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(Greeted::class, $captured);
        self::assertSame('Hello, Alice!', $captured->greeting);
    }
}
```

The pattern:

1. Create `FiberRuntime` and `ActorSystem`.
2. Spawn a probe actor that captures received messages into a closure variable.
3. Spawn the actor under test.
4. Send messages.
5. Schedule shutdown — without this, `run()` never exits.
6. Call `$system->run()` — blocks until shutdown.
7. Assert on captured values.

### Testing a stateful actor

Stateful actors carry their state between messages. Test by sending a sequence of state-changing messages, then querying the state.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Actor;

use App\Actor\Message\CountResult;
use App\Actor\Message\GetCount;
use App\Actor\Message\Increment;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CounterActorTest extends TestCase
{
    #[Test]
    public function counter_accumulates_increments(): void
    {
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        $counterBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                if ($msg instanceof Increment) {
                    return BehaviorWithState::next($count + 1);
                }

                if ($msg instanceof GetCount) {
                    $ctx->reply(new CountResult($count));

                    return BehaviorWithState::same();
                }

                return BehaviorWithState::same();
            },
        );

        $counter = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        /** @var CountResult|null $result */
        $result = null;

        $runtime->spawn(static function () use ($counter, &$result): void {
            for ($i = 0; $i < 5; $i++) {
                $counter->tell(new Increment());
            }

            $result = $counter->ask(new GetCount(), Duration::seconds(5))->await();
        });

        $runtime->scheduleOnce(Duration::millis(500), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(CountResult::class, $result);
        self::assertSame(5, $result->value);
    }

    #[Test]
    public function counter_starts_at_zero(): void
    {
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        $counterBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                if ($msg instanceof GetCount) {
                    $ctx->reply(new CountResult($count));

                    return BehaviorWithState::same();
                }

                return BehaviorWithState::same();
            },
        );

        $counter = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        /** @var CountResult|null $result */
        $result = null;

        $runtime->spawn(static function () use ($counter, &$result): void {
            $result = $counter->ask(new GetCount(), Duration::seconds(5))->await();
        });

        $runtime->scheduleOnce(Duration::millis(200), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(CountResult::class, $result);
        self::assertSame(0, $result->value);
    }
}
```

### Testing actor supervision — child failure and restart

Supervision is tested by spawning an actor that throws on a known message, then verifying the parent receives `ChildFailed` or the actor restarts cleanly.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Lifecycle\ChildFailed;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

readonly class Explode {}
readonly class Ping {}
readonly class Pong {}

final class SupervisionTest extends TestCase
{
    #[Test]
    public function parent_receives_child_failed_signal_on_exception(): void
    {
        $runtime  = new FiberRuntime();
        $system   = ActorSystem::create('test', $runtime);

        /** @var ChildFailed|null $signal */
        $signal = null;

        $parentBehavior = Behavior::setup(
            static function (ActorContext $ctx) use (&$signal): Behavior {
                $childBehavior = Behavior::receive(
                    static function (ActorContext $ctx, object $msg): Behavior {
                        if ($msg instanceof Explode) {
                            throw new RuntimeException('Child explosion');
                        }

                        return Behavior::same();
                    },
                );

                $child = $ctx->spawn(Props::fromBehavior($childBehavior), 'child');
                $child->tell(new Explode());

                return Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same())
                    ->onSignal(
                        static function (ActorContext $ctx, Signal $sig) use (&$signal): Behavior {
                            if ($sig instanceof ChildFailed) {
                                $signal = $sig;
                            }

                            return Behavior::same();
                        },
                    );
            },
        );

        $system->spawn(Props::fromBehavior($parentBehavior), 'parent');

        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(ChildFailed::class, $signal);
        self::assertStringContainsString('child', (string) $signal->child->path());
    }
}
```

### Testing ask() request-response

`ask()` must be called from within a Fiber context. Spawn a fiber via `$runtime->spawn()` and call `await()` inside it.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Actor;

use App\Actor\Message\Price;
use App\Actor\Message\QuoteRequest;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PricingActorTest extends TestCase
{
    #[Test]
    public function ask_returns_price_for_known_product(): void
    {
        $runtime  = new FiberRuntime();
        $system   = ActorSystem::create('test', $runtime);

        $pricingBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof QuoteRequest) {
                    $ctx->reply(new Price(sku: $msg->sku, amount: 29.99));
                }

                return Behavior::same();
            },
        );

        $actor = $system->spawn(Props::fromBehavior($pricingBehavior), 'pricing');

        /** @var Price|null $result */
        $result = null;

        $runtime->spawn(static function () use ($actor, &$result): void {
            $result = $actor->ask(new QuoteRequest('widget-001'), Duration::seconds(5))->await();
        });

        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(Price::class, $result);
        self::assertSame('widget-001', $result->sku);
        self::assertEqualsWithDelta(29.99, $result->amount, 0.001);
    }

    #[Test]
    public function ask_times_out_when_actor_does_not_reply(): void
    {
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        $silentBehavior = Behavior::receive(static fn(ActorContext $ctx, object $msg) => Behavior::same());
        $actor          = $system->spawn(Props::fromBehavior($silentBehavior), 'silent');

        /** @var AskTimeoutException|null $caught */
        $caught = null;

        $runtime->spawn(static function () use ($actor, &$caught): void {
            try {
                $actor->ask(new QuoteRequest('widget-001'), Duration::millis(100))->await();
            } catch (AskTimeoutException $e) {
                $caught = $e;
            }
        });

        $runtime->scheduleOnce(Duration::millis(400), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(AskTimeoutException::class, $caught);
    }
}
```

---

## Unit testing actors with StepRuntime

`StepRuntime` is the deterministic test runtime. It uses PHP Fibers internally but processes exactly one message at a time — no concurrency, no real clock. This eliminates timing dependencies from tests.

```php
use Monadial\Nexus\Runtime\Step\StepRuntime;
```

`StepRuntime` is in `monadial/nexus-runtime-step`.

### Basic step-by-step example

```php
<?php

declare(strict_types=1);

namespace App\Tests\Actor;

use App\Actor\Message\CountResult;
use App\Actor\Message\GetCount;
use App\Actor\Message\Increment;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CounterStepTest extends TestCase
{
    #[Test]
    public function counter_processes_messages_one_at_a_time(): void
    {
        $runtime = new StepRuntime();
        $system  = ActorSystem::create('test', $runtime);

        $counterBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                if ($msg instanceof Increment) {
                    return BehaviorWithState::next($count + 1);
                }

                if ($msg instanceof GetCount) {
                    $ctx->reply(new CountResult($count));

                    return BehaviorWithState::same();
                }

                return BehaviorWithState::same();
            },
        );

        /** @var list<CountResult> $captured */
        $captured = [];

        $probeBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
                if ($msg instanceof CountResult) {
                    $captured[] = $msg;
                }

                return Behavior::same();
            },
        );

        $counter = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');
        $probe   = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Send three increments
        $counter->tell(new Increment());
        $counter->tell(new Increment());
        $counter->tell(new Increment());

        // Process all three increment messages
        $runtime->drain();

        // Send a query
        $counter->tell(new GetCount());

        // Process the GetCount message and the CountResult delivery to probe
        $runtime->drain();

        self::assertCount(1, $captured);
        self::assertSame(3, $captured[0]->value);
    }

    #[Test]
    public function step_processes_exactly_one_message(): void
    {
        $runtime = new StepRuntime();
        $system  = ActorSystem::create('test', $runtime);

        $counterBehavior = Behavior::withState(
            0,
            static function (ActorContext $ctx, object $msg, int $count): BehaviorWithState {
                return $msg instanceof Increment
                    ? BehaviorWithState::next($count + 1)
                    : BehaviorWithState::same();
            },
        );

        $counter = $system->spawn(Props::fromBehavior($counterBehavior), 'counter');

        $counter->tell(new Increment());
        $counter->tell(new Increment());

        // step() processes exactly one message and returns true if work was done
        $processed = $runtime->step();
        self::assertTrue($processed);

        // One more message remains
        self::assertSame(1, $runtime->pendingMessageCount());

        $runtime->step();

        // All messages consumed
        self::assertTrue($runtime->isIdle());
    }
}
```

### StepRuntime API reference

| Method | Description |
|--------|-------------|
| `step(): bool` | Process exactly one message from one actor. Returns `true` if a message was processed, `false` if all actors are idle. |
| `drain(): void` | Process all pending messages until idle. |
| `advanceTime(Duration): void` | Move the virtual clock forward and fire any due timers. |
| `pendingMessageCount(): int` | Total pending messages across all actor mailboxes. |
| `isIdle(): bool` | Returns `true` when no actor has pending messages. |
| `clock(): VirtualClock` | Access the virtual clock for inspection. |

### Testing scheduled messages with StepRuntime

`advanceTime()` fires timers deterministically without sleeping.

```php
#[Test]
public function actor_sends_reminder_after_delay(): void
{
    $runtime = new StepRuntime();
    $system  = ActorSystem::create('test', $runtime);

    /** @var list<object> $received */
    $received = [];

    $remindBehavior = Behavior::setup(
        static function (ActorContext $ctx) use (&$received): Behavior {
            $ctx->scheduleOnce(Duration::seconds(30), new Reminder('check-in'));

            return Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                    $received[] = $msg;

                    return Behavior::same();
                },
            );
        },
    );

    $system->spawn(Props::fromBehavior($remindBehavior), 'scheduler');
    $runtime->drain(); // boot the actor (PreStart → Running)

    // Timer has not fired yet
    self::assertCount(0, $received);

    // Advance past the 30-second mark
    $runtime->advanceTime(Duration::seconds(31));
    $runtime->drain();

    self::assertCount(1, $received);
    self::assertInstanceOf(Reminder::class, $received[0]);
    self::assertSame('check-in', $received[0]->label);
}
```

---

## Testing actors that use Symfony services

Actors with constructor dependencies are resolved from the DI container when spawned in production. In unit tests, construct the actor directly with mock dependencies.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Actor;

use App\Actor\CatalogActor;
use App\Actor\Message\GetProduct;
use App\Actor\Message\ProductDetail;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;

final class CatalogActorTest extends TestCase
{
    #[Test]
    public function get_product_replies_with_product_detail(): void
    {
        // Create a real cache stub — using an in-memory implementation
        // avoids mocking the complex CacheInterface::get() callback pattern
        $cache = new ArrayCacheAdapter();

        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        // Inject mock directly into the actor — no kernel needed
        $actor = $system->spawn(
            Props::fromFactory(fn() => new CatalogActor($cache)),
            'catalog',
        );

        /** @var ProductDetail|null $result */
        $result = null;

        $runtime->spawn(static function () use ($actor, &$result): void {
            $result = $actor->ask(new GetProduct('chair-001'), Duration::seconds(5))->await();
        });

        $runtime->scheduleOnce(Duration::millis(300), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(ProductDetail::class, $result);
        self::assertSame('chair-001', $result->product->id);
    }

    #[Test]
    public function get_product_does_not_reply_for_unknown_id(): void
    {
        $cache   = new ArrayCacheAdapter();
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        $actor    = $system->spawn(Props::fromFactory(fn() => new CatalogActor($cache)), 'catalog');
        $captured = null;

        $probeBehavior = Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
                $captured = $msg;

                return Behavior::same();
            },
        );

        $probe = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');
        $actor->tell(new GetProduct('nonexistent-999'));

        $runtime->scheduleOnce(Duration::millis(200), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        // No reply expected for an unknown product
        self::assertNull($captured);
    }
}
```

Using `Props::fromFactory()` with a closure keeps dependency injection explicit. The test has full control over which implementation is injected.

---

## Test doubles for actors

A stub actor is a minimal behavior that responds to known messages with predetermined replies. Use stubs to replace real actors in controller and integration tests.

```php
// Stub that always returns a fixed price
$priceStub = $system->spawn(
    Props::fromBehavior(
        Behavior::receive(
            static function (ActorContext $ctx, object $msg): Behavior {
                if ($msg instanceof QuoteRequest) {
                    $ctx->reply(new Price(sku: $msg->sku, amount: 9.99));
                }

                return Behavior::same();
            },
        ),
    ),
    'price-service',
);
```

For stubs that need to verify received messages (spy behavior):

```php
/** @var list<object> $received */
$received = [];

$spyBehavior = Behavior::receive(
    static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
        $received[] = $msg;

        // Reply to keep ask() futures from timing out
        if ($msg instanceof QuoteRequest) {
            $ctx->reply(new Price(sku: $msg->sku, amount: 0.0));
        }

        return Behavior::same();
    },
);

$spy = $system->spawn(Props::fromBehavior($spyBehavior), 'price-spy');

// ... exercise the code under test ...

self::assertCount(1, $received);
self::assertInstanceOf(QuoteRequest::class, $received[0]);
self::assertSame('widget-001', $received[0]->sku);
```

---

## Testing controllers that use actors

Controllers inject `ActorRef` via `nexus.actor_ref.{name}`. In test environments, the simplest approach is to boot the full Symfony kernel with actors replaced by stubs.

### Option 1: Symfony WebTestCase with stub service override

Override the actor ref service in the test container by defining a stub actor in the test environment's service configuration.

```yaml
# config/services_test.yaml
services:
    nexus.actor_ref.catalog:
        class: Monadial\Nexus\Core\Actor\DeadLetterRef
        factory: ['App\Tests\Stub\CatalogActorStub', 'create']
```

A stub factory creates a test actor ref backed by a pre-configured behavior:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Actor\Message\GetProduct;
use App\Actor\Message\GetProducts;
use App\Actor\Message\Product;
use App\Actor\Message\ProductDetail;
use App\Actor\Message\ProductList;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Step\StepRuntime;

final class CatalogActorStub
{
    public static function create(): ActorRef
    {
        $runtime = new StepRuntime();
        $system  = ActorSystem::create('test-stub', $runtime);

        return $system->spawn(
            Props::fromBehavior(
                Behavior::receive(
                    static function (ActorContext $ctx, object $msg): Behavior {
                        if ($msg instanceof GetProducts) {
                            $ctx->reply(new ProductList([
                                new Product('Test Chair', 'chair-001', 'Test Chair', 49.99),
                            ]));
                        }

                        if ($msg instanceof GetProduct) {
                            $ctx->reply(new ProductDetail(
                                new Product('Test Chair', 'chair-001', 'Test Chair', 49.99),
                            ));
                        }

                        return Behavior::same();
                    },
                ),
            ),
            'catalog-stub',
        );
    }
}
```

### Option 2: WebTestCase with a mock ActorRef

For simpler cases, create a mock `ActorRef` using PHPUnit and set it on the container:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Actor\Message\GetProducts;
use App\Actor\Message\Product;
use App\Actor\Message\ProductList;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogControllerTest extends WebTestCase
{
    #[Test]
    public function list_returns_products_from_catalog_actor(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Create a stub ActorRef that replies immediately
        $catalogStub = $this->createMock(ActorRef::class);

        $productList = new ProductList([
            new Product('Test Chair', 'chair-001', 'Test Chair', 49.99),
        ]);

        $future = Future::resolved($productList);
        $catalogStub
            ->method('ask')
            ->with(self::isInstanceOf(GetProducts::class), self::isInstanceOf(Duration::class))
            ->willReturn($future);

        $container->set('nexus.actor_ref.catalog', $catalogStub);

        $client->request('GET', '/catalog');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $data['products']);
        self::assertSame('chair-001', $data['products'][0]['id']);
    }
}
```

> **Note:** `Future::resolved()` creates an already-resolved future. This keeps the test synchronous — the `FutureResponseListener` calls `await()`, which returns immediately without suspending any coroutine.

---

## Integration testing with the kernel and TestRuntime

For integration tests that boot the full Symfony kernel but avoid Swoole, configure nexus to use `FiberRuntime` or `StepRuntime` in the test environment.

### Configuring TestRuntime in config

```yaml
# config/packages/test/nexus.yaml
nexus:
    name: test-app
    shutdown_timeout: 5
```

The runtime itself is configured via environment variables. In `phpunit.xml` or `.env.test`:

```dotenv
# .env.test
APP_ENV=test
APP_DEBUG=true
# Do not set APP_RUNTIME — let the kernel use the default Symfony Runtime
# Actors in test use StepRuntime via service override
```

Override the actor system and runtime in test service definitions:

```yaml
# config/services_test.yaml
services:
    Monadial\Nexus\Core\Actor\ActorSystem:
        synthetic: true

    nexus.actor_system:
        alias: Monadial\Nexus\Core\Actor\ActorSystem
```

### Functional test with a real actor system

Boot the kernel, create an actor system with `StepRuntime`, set it in the container, and test the full request cycle:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Actor\CatalogActor;
use App\Actor\InventoryActor;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogActorIntegrationTest extends KernelTestCase
{
    #[Test]
    public function catalog_actor_is_reachable_from_container(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $runtime = new StepRuntime();
        $system  = ActorSystem::create('test', $runtime);

        // Spawn a catalog actor with the real service from the container
        $catalog = $system->spawn(
            Props::fromContainer($container, CatalogActor::class),
            'catalog',
        );

        $container->set('nexus.actor_ref.catalog', $catalog);
        $container->set('nexus.actor_system', $system);

        // The actor ref is now available to any service in the container
        $ref = $container->get('nexus.actor_ref.catalog');

        self::assertSame($catalog, $ref);
        self::assertTrue($ref->isAlive());
    }
}
```

---

## Functional HTTP tests

Standard Symfony `WebTestCase` tests work with nexus-symfony controllers. The `ask()` call is intercepted by `FutureResponseListener` which calls `await()`. In the test environment (no Swoole), `Future` resolution is synchronous via `StepRuntime`.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeActionTest extends WebTestCase
{
    #[Test]
    public function home_returns_worker_name(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('worker', $data);
    }

    #[Test]
    public function catalog_list_returns_products(): void
    {
        $client = static::createClient();
        $client->request('GET', '/catalog');

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('products', $data);
        self::assertIsArray($data['products']);
    }

    #[Test]
    public function catalog_show_returns_404_for_unknown_product(): void
    {
        $client = static::createClient();
        $client->request('GET', '/catalog/nonexistent-999');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function counter_increment_returns_accepted(): void
    {
        $client = static::createClient();
        $client->request('POST', '/counter/increment');

        self::assertResponseStatusCodeSame(202);
    }
}
```

These tests run without Swoole. The Symfony `HttpKernel` handles requests synchronously. Actors instantiated via `Props::fromContainer()` use the real container services. `tell()` enqueues to the actor's mailbox (which is drained synchronously in test mode), and `ask()` resolves immediately.

---

## Testing the kernel pool

`KernelPoolActor` routes requests to idle kernels. Verify the routing behavior in isolation by constructing the pool actor directly with a test kernel factory.

```php
<?php

declare(strict_types=1);

namespace App\Tests\KernelPool;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Symfony\KernelPool\KernelPoolActor;
use Monadial\Nexus\Symfony\KernelPool\Message\HandleRequest;
use Monadial\Nexus\Symfony\KernelPool\Message\KernelResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class KernelPoolActorTest extends TestCase
{
    #[Test]
    public function pool_handles_single_request(): void
    {
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        // Minimal kernel stub — always returns 200 OK
        $kernelFactory = static function (): HttpKernelInterface {
            return new class implements HttpKernelInterface {
                public function handle(
                    Request $request,
                    int $type = self::MAIN_REQUEST,
                    bool $catch = true,
                ): Response {
                    return new Response('OK', 200);
                }
            };
        };

        $poolRef = $system->spawn(
            KernelPoolActor::props($kernelFactory, $system, $runtime, poolSize: 2, maxPending: 10),
            'pool',
        );

        /** @var KernelResponse|null $result */
        $result = null;

        $runtime->spawn(static function () use ($poolRef, &$result): void {
            $result = $poolRef
                ->ask(new HandleRequest(Request::create('/')), Duration::seconds(5))
                ->await();
        });

        $runtime->scheduleOnce(Duration::millis(500), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertInstanceOf(KernelResponse::class, $result);
        self::assertSame(200, $result->response->getStatusCode());
    }

    #[Test]
    public function pool_handles_concurrent_requests_up_to_pool_size(): void
    {
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        $requestCount = 0;

        $kernelFactory = static function () use (&$requestCount): HttpKernelInterface {
            return new class ($requestCount) implements HttpKernelInterface {
                public function __construct(private int &$count) {}

                public function handle(
                    Request $request,
                    int $type = self::MAIN_REQUEST,
                    bool $catch = true,
                ): Response {
                    $this->count++;

                    return new Response('OK', 200);
                }
            };
        };

        $poolRef = $system->spawn(
            KernelPoolActor::props($kernelFactory, $system, $runtime, poolSize: 3, maxPending: 10),
            'pool',
        );

        /** @var list<KernelResponse> $results */
        $results = [];

        $runtime->spawn(static function () use ($poolRef, &$results): void {
            $futures = [];

            for ($i = 0; $i < 3; $i++) {
                $futures[] = $poolRef->ask(new HandleRequest(Request::create('/')), Duration::seconds(5));
            }

            foreach ($futures as $future) {
                $results[] = $future->await();
            }
        });

        $runtime->scheduleOnce(Duration::millis(1000), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertCount(3, $results);

        foreach ($results as $response) {
            self::assertSame(200, $response->response->getStatusCode());
        }

        self::assertSame(3, $requestCount);
    }

    #[Test]
    public function pool_returns_503_when_pending_queue_is_full(): void
    {
        $runtime = new FiberRuntime();
        $system  = ActorSystem::create('test', $runtime);

        // A kernel that blocks indefinitely — simulates slow handler
        $kernelFactory = static function (): HttpKernelInterface {
            return new class implements HttpKernelInterface {
                public function handle(
                    Request $request,
                    int $type = self::MAIN_REQUEST,
                    bool $catch = true,
                ): Response {
                    // In a real Swoole environment this would yield.
                    // In tests the actor system processes messages sequentially.
                    return new Response('Slow', 200);
                }
            };
        };

        // Pool size 1, max pending 0 — any overflow should be rejected immediately
        $poolRef = $system->spawn(
            KernelPoolActor::props($kernelFactory, $system, $runtime, poolSize: 1, maxPending: 0),
            'pool',
        );

        /** @var list<KernelResponse> $results */
        $results = [];

        $runtime->spawn(static function () use ($poolRef, &$results): void {
            // Send two requests — first occupies the one kernel, second overflows
            $f1 = $poolRef->ask(new HandleRequest(Request::create('/')), Duration::seconds(5));
            $f2 = $poolRef->ask(new HandleRequest(Request::create('/')), Duration::seconds(5));

            $results[] = $f1->await();
            $results[] = $f2->await();
        });

        $runtime->scheduleOnce(Duration::millis(500), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        self::assertCount(2, $results);

        $statuses = array_map(fn(KernelResponse $r) => $r->response->getStatusCode(), $results);
        self::assertContains(503, $statuses, 'Expected at least one 503 when pool overflows');
    }
}
```

---

## Testing with time

`TestClock` (available from `nexus-core`'s test support) and `StepRuntime`'s `advanceTime()` allow deterministic time control.

### Using TestClock with FiberRuntime

`FiberRuntime` uses real wall-clock time. For time-sensitive tests, prefer `StepRuntime` with its virtual clock. For rare cases where `FiberRuntime` is needed with time control, pass a `TestClock` to the `ActorSystem`:

```php
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;

$clock   = new TestClock();
$runtime = new TestRuntime($clock);
$system  = ActorSystem::create('test', $runtime, $clock);

// Advance time without sleeping
$runtime->advanceTime(Duration::seconds(60));
$runtime->fireDueTimers();
```

`TestRuntime` is a lightweight no-op runtime from `nexus-core`'s test support. It does not execute actor Fibers — it is suitable for unit tests that only need to verify spawning or timer registration, not actual message processing.

### Using StepRuntime with virtual clock

```php
#[Test]
public function actor_does_not_send_before_delay(): void
{
    $runtime = new StepRuntime();
    $system  = ActorSystem::create('test', $runtime);

    /** @var list<object> $received */
    $received = [];

    $schedulingBehavior = Behavior::setup(
        static function (ActorContext $ctx) use (&$received): Behavior {
            $ctx->scheduleOnce(Duration::seconds(10), new Reminder('ten-second-check'));

            return Behavior::receive(
                static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                    $received[] = $msg;

                    return Behavior::same();
                },
            );
        },
    );

    $system->spawn(Props::fromBehavior($schedulingBehavior), 'scheduler');
    $runtime->drain();

    // 5 seconds elapsed — timer has not fired
    $runtime->advanceTime(Duration::seconds(5));
    $runtime->drain();
    self::assertCount(0, $received);

    // 10 more seconds — timer fires
    $runtime->advanceTime(Duration::seconds(10));
    $runtime->drain();
    self::assertCount(1, $received);
    self::assertInstanceOf(Reminder::class, $received[0]);
}
```

---

## PHPUnit configuration

### phpunit.xml

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="vendor/autoload.php"
    colors="true"
    failOnRisky="true"
    failOnWarning="true"
>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="functional">
            <directory>tests/Functional</directory>
        </testsuite>
    </testsuites>

    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>

    <coverage>
        <report>
            <clover outputFile="clover.xml"/>
        </report>
    </coverage>

    <php>
        <ini name="memory_limit" value="512M"/>
        <env name="APP_ENV" value="test"/>
        <env name="APP_DEBUG" value="true"/>
        <env name="KERNEL_CLASS" value="App\Kernel"/>
    </php>
</phpunit>
```

### Recommended test directory layout

```
tests/
├── Unit/
│   └── Actor/
│       ├── CatalogActorTest.php       # Actor logic, no kernel
│       ├── CounterActorTest.php
│       └── InventoryActorTest.php
├── Integration/
│   ├── Actor/
│   │   └── CatalogActorIntegrationTest.php  # Kernel + real services
│   └── KernelPool/
│       └── KernelPoolActorTest.php
└── Functional/
    └── Controller/
        ├── CatalogControllerTest.php   # HTTP tests via WebTestCase
        ├── HomeActionTest.php
        └── OrderControllerTest.php
```

### Running specific suites

```bash
# Unit tests only — fast, no kernel
docker compose exec php vendor/bin/phpunit --testsuite=unit

# Integration tests
docker compose exec php vendor/bin/phpunit --testsuite=integration

# Functional (HTTP) tests
docker compose exec php vendor/bin/phpunit --testsuite=functional

# Single test class
docker compose exec php vendor/bin/phpunit tests/Unit/Actor/CatalogActorTest.php

# Single test method
docker compose exec php vendor/bin/phpunit --filter=get_product_replies_with_product_detail tests/Unit/Actor/CatalogActorTest.php
```

### Test environment service configuration

Create `config/packages/test/nexus.yaml` for test-specific configuration:

```yaml
# config/packages/test/nexus.yaml
nexus:
    name: test-app
    shutdown_timeout: 5
```

Disable Swoole-specific features that are not available without the extension:

```yaml
# config/services_test.yaml
services:
    # Override worker start bootstrappers that require Swoole
    App\Infrastructure\ConnectionPoolBootstrapper:
        class: App\Tests\Stub\NoopBootstrapper
```

```php
<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use Monadial\Nexus\Symfony\Runtime\WorkerStartBootstrapper;
use Psr\Container\ContainerInterface;

final class NoopBootstrapper implements WorkerStartBootstrapper
{
    public function onWorkerStart(ContainerInterface $container, int $workerId): void
    {
        // no-op in test environment
    }
}
```

---

## Summary

| Test type | Runtime | Kernel | Use case |
|-----------|---------|--------|----------|
| Actor unit test | `FiberRuntime` | No | Fast isolation tests for actor logic |
| Actor unit test (deterministic) | `StepRuntime` | No | Time-sensitive tests, step-by-step verification |
| Actor unit test (no fibers) | `TestRuntime` | No | Verifying spawn/timer registration only |
| Actor integration test | `FiberRuntime` | Yes | Actor + real Symfony services |
| Controller test | `WebTestCase` + stub `ActorRef` | Yes | HTTP layer, message types, response shape |
| Functional HTTP test | `WebTestCase` | Yes | End-to-end request/response |
| Kernel pool test | `FiberRuntime` | No | Pool routing, backpressure, crash recovery |
