---
sidebar_position: 3
title: Props
---

# Props

`Props<T>` is a `final readonly class` that holds the configuration needed to spawn an actor. It combines a `Behavior`, a `MailboxConfig`, and an optional `SupervisionStrategy` into a single immutable value. `Props` is never instantiated directly — use one of the static factory methods and chain builder methods as needed.

## Factory methods

### Props::fromBehavior

The most common way to create `Props`. Pass a `Behavior` directly.

```php
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

readonly class Ping
{
    public function __construct(public string $from) {}
}

$behavior = Behavior::receive(
    static function (ActorContext $ctx, Ping $msg): Behavior {
        $ctx->log()->info("Ping from {$msg->from}");

        return Behavior::same();
    },
);

$props = Props::fromBehavior($behavior);
$ref = $system->spawn($props, 'pinger');
```

Defaults:
- Mailbox: unbounded (`MailboxConfig::unbounded()`)
- Supervision: none (the system applies `SupervisionStrategy::oneForOne()` as a default)

Signature:

```php
/**
 * @template U of object
 * @param Behavior<U> $behavior
 * @return Props<U>
 */
public static function fromBehavior(Behavior $behavior): self;
```

### Props::fromFactory

Creates `Props` from a callable that returns an `ActorHandler` instance. A fresh instance is created per spawn inside `Behavior::setup`. If the returned instance extends `AbstractActor`, lifecycle hooks (`onPreStart`, `onPostStop`) are wired automatically.

```php
use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;

readonly class ProcessJob
{
    public function __construct(public string $payload) {}
}

/** @extends AbstractActor<ProcessJob> */
final class WorkerActor extends AbstractActor
{
    public function __construct(private readonly string $workerId) {}

    public function onPreStart(ActorContext $ctx): void
    {
        $ctx->log()->info("Worker {$this->workerId} starting");
    }

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof ProcessJob) {
            $ctx->log()->info("Worker {$this->workerId} processing: {$message->payload}");
        }

        return Behavior::same();
    }

    public function onPostStop(ActorContext $ctx): void
    {
        $ctx->log()->info("Worker {$this->workerId} stopped");
    }
}

$props = Props::fromFactory(fn () => new WorkerActor('w-001'));
$ref = $system->spawn($props, 'worker');
```

Signature:

```php
/**
 * @template U of object
 * @param callable(): ActorHandler<U> $factory
 * @return Props<U>
 */
public static function fromFactory(callable $factory): self;
```

### Props::fromContainer

Creates `Props` from a PSR-11 dependency injection container. A fresh actor instance is resolved via `$container->get($actorClass)` on each spawn. This is the recommended approach for actors with complex dependencies.

```php
use Monadial\Nexus\Core\Actor\Props;
use Psr\Container\ContainerInterface;

// Assuming your DI container is configured to produce OrderActor instances:
/** @var ContainerInterface $container */
$props = Props::fromContainer($container, OrderActor::class);
$ref = $system->spawn($props, 'order-processor');
```

Under the hood, this delegates to `Props::fromFactory()`, so lifecycle hooks on `AbstractActor` subclasses work the same way.

Signature:

```php
/**
 * @template U of object
 * @param class-string<ActorHandler<U>> $actorClass
 * @return Props<U>
 */
public static function fromContainer(ContainerInterface $container, string $actorClass): self;
```

### Props::fromStatefulFactory

Creates `Props` for a `StatefulActorHandler`. The factory produces a fresh handler instance per spawn, and the actor's state is managed via `Behavior::withState` internally.

```php
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;

readonly class Deposit
{
    public function __construct(public int $amount) {}
}

readonly class Withdraw
{
    public function __construct(public int $amount) {}
}

/** @implements StatefulActorHandler<Deposit|Withdraw, int> */
final class AccountActor implements StatefulActorHandler
{
    public function __construct(private readonly string $accountId) {}

    public function initialState(): int
    {
        return 0; // starting balance
    }

    public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        return match (true) {
            $message instanceof Deposit => BehaviorWithState::next($state + $message->amount),
            $message instanceof Withdraw => BehaviorWithState::next($state - $message->amount),
            default => BehaviorWithState::same(),
        };
    }
}

$props = Props::fromStatefulFactory(fn () => new AccountActor('acc-42'));
$ref = $system->spawn($props, 'account-42');
```

Signature:

```php
/**
 * @template U of object
 * @template S
 * @param callable(): StatefulActorHandler<U, S> $factory
 * @return Props<U>
 */
public static function fromStatefulFactory(callable $factory): self;
```

## Builder methods

`Props` is immutable. Each builder method returns a new `Props` instance with the specified configuration applied, leaving the original unchanged.

### withMailbox

Configure the actor's mailbox. By default, actors use an unbounded mailbox. Use `MailboxConfig::bounded()` to set a capacity limit and overflow strategy.

```php
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(1000));

// With a specific overflow strategy
$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(500, OverflowStrategy::DropOldest));
```

Available overflow strategies:

| Strategy | Description |
|---|---|
| `OverflowStrategy::ThrowException` | Throw `MailboxOverflowException` (default) |
| `OverflowStrategy::DropNewest` | Discard the newest message being enqueued |
| `OverflowStrategy::DropOldest` | Discard the oldest message in the mailbox |
| `OverflowStrategy::Backpressure` | Block the sender until space is available |

### withSupervision

Set the supervision strategy that governs how the actor handles child failures.

```php
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Supervision\Directive;
use Monadial\Nexus\Runtime\Duration;

// One-for-one: only the failed child is restarted
$props = Props::fromBehavior($behavior)
    ->withSupervision(SupervisionStrategy::oneForOne(maxRetries: 5));

// All-for-one: all children are restarted when one fails
$props = Props::fromBehavior($behavior)
    ->withSupervision(SupervisionStrategy::allForOne(
        maxRetries: 3,
        window: Duration::seconds(60),
    ));

// Exponential backoff: restarts with increasing delays
$props = Props::fromBehavior($behavior)
    ->withSupervision(SupervisionStrategy::exponentialBackoff(
        initialBackoff: Duration::millis(100),
        maxBackoff: Duration::seconds(10),
        maxRetries: 5,
        multiplier: 2.0,
    ));

// Custom decider: choose a directive based on the exception type
$props = Props::fromBehavior($behavior)
    ->withSupervision(SupervisionStrategy::oneForOne(
        maxRetries: 3,
        decider: fn (\Throwable $e) => match (true) {
            $e instanceof \InvalidArgumentException => Directive::Resume,
            $e instanceof \RuntimeException => Directive::Restart,
            default => Directive::Escalate,
        },
    ));
```

### Chaining builder methods

Builder methods can be chained fluently:

```php
$props = Props::fromFactory(fn () => new WorkerActor())
    ->withMailbox(MailboxConfig::bounded(500))
    ->withSupervision(SupervisionStrategy::exponentialBackoff(
        initialBackoff: Duration::millis(200),
        maxBackoff: Duration::seconds(30),
    ));
```

## Pipe operator support (PHP 8.5+)

Nexus ships pipe-friendly functions in the `Monadial\Nexus\Core\Actor\Functions` namespace. These return closures compatible with the PHP 8.5 pipe operator (`|>`), enabling a left-to-right composition style from behavior to fully-configured Props.

```php
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Duration;

use function Monadial\Nexus\Core\Actor\Functions\withMailbox;
use function Monadial\Nexus\Core\Actor\Functions\withSupervision;

$props = $behavior
    |> Props::fromBehavior(...)
    |> withMailbox(MailboxConfig::bounded(500))
    |> withSupervision(SupervisionStrategy::exponentialBackoff(
        initialBackoff: Duration::millis(100),
        maxBackoff: Duration::seconds(10),
    ));
```

The pipe functions are thin wrappers:

```php
// withMailbox returns a Closure(Props): Props
function withMailbox(MailboxConfig $config): Closure
{
    return static fn (Props $props): Props => $props->withMailbox($config);
}

// withSupervision returns a Closure(Props): Props
function withSupervision(SupervisionStrategy $strategy): Closure
{
    return static fn (Props $props): Props => $props->withSupervision($strategy);
}
```

This style reads naturally as a pipeline: take a behavior, wrap it in Props, configure the mailbox, then configure supervision.
