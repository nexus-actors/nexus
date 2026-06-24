---
sidebar_position: 3
title: "Props"
related:
  - core-concepts/actors
  - core-concepts/behaviors
  - core-concepts/mailboxes
  - core-concepts/supervision
---

# Props

`Props<T>` is a `final readonly class` that holds everything needed to spawn an actor: a `Behavior`, a `MailboxConfig`, and an optional `SupervisionStrategy`. You never instantiate `Props` directly — use one of the static factory methods and chain builder methods as needed.

## Factory methods

### Props::fromBehavior

The most common way to create `Props`. Pass a `Behavior` directly.

```php title="src/Actor/PingerProps.php"
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

Defaults: unbounded mailbox (`MailboxConfig::unbounded()`), system default supervision (`SupervisionStrategy::oneForOne()`).

### Props::fromFactory

Creates `Props` from a callable that returns an `ActorHandler` instance. A fresh instance is created per spawn inside `Behavior::setup`. If the returned instance extends `AbstractActor`, lifecycle hooks (`onPreStart`, `onPostStop`) are wired automatically.

```php title="src/Actor/WorkerProps.php"
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
            $ctx->log()->info("Processing: {$message->payload}");
        }

        return Behavior::same();
    }

    public function onPostStop(ActorContext $ctx): void
    {
        $ctx->log()->info("Worker {$this->workerId} stopped");
    }
}

$props = Props::fromFactory(fn () => new WorkerActor('w-001'));
```

### Props::fromContainer

Creates `Props` from a PSR-11 dependency injection container. A fresh actor instance is resolved via `$container->get($actorClass)` on each spawn. This is the recommended approach for actors with complex dependencies.

```php title="src/Actor/ContainerProps.php"
use Monadial\Nexus\Core\Actor\Props;
use Psr\Container\ContainerInterface;

/** @var ContainerInterface $container */
$props = Props::fromContainer($container, OrderActor::class);
$ref = $system->spawn($props, 'order-processor');
```

Delegates to `Props::fromFactory()` internally, so lifecycle hooks on `AbstractActor` subclasses work the same way.

### Props::fromStatefulFactory

Creates `Props` for a `StatefulActorHandler`. The factory produces a fresh handler instance per spawn, and the actor's state is managed via `Behavior::withState` internally.

```php title="src/Actor/AccountProps.php"
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
    public function initialState(): int
    {
        return 0;
    }

    public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
    {
        return match (true) {
            $message instanceof Deposit  => BehaviorWithState::next($state + $message->amount),
            $message instanceof Withdraw => BehaviorWithState::next($state - $message->amount),
            default => BehaviorWithState::same(),
        };
    }
}

$props = Props::fromStatefulFactory(fn () => new AccountActor());
$ref = $system->spawn($props, 'account-42');
```

## Builder methods

`Props` is immutable. Each builder method returns a new `Props` instance with the configuration applied, leaving the original unchanged.

### withMailbox

Configure the actor's mailbox capacity and overflow strategy.

```php title="src/Actor/BoundedProps.php"
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

$props = Props::fromBehavior($behavior)
    ->withMailbox(MailboxConfig::bounded(500, OverflowStrategy::DropOldest));
```

See [Mailboxes](./mailboxes.md) for all overflow strategies and their tradeoffs.

### withSupervision

Set the supervision strategy that governs how the actor handles child failures.

```php title="src/Actor/SupervisedProps.php"
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Supervision\Directive;
use Monadial\Nexus\Runtime\Duration;

$props = Props::fromBehavior($behavior)
    ->withSupervision(SupervisionStrategy::exponentialBackoff(
        initialBackoff: Duration::millis(100),
        maxBackoff: Duration::seconds(10),
        maxRetries: 5,
        multiplier: 2.0,
    ));
```

### Chaining builder methods

```php title="src/Actor/FullyConfiguredProps.php"
$props = Props::fromFactory(fn () => new WorkerActor())
    ->withMailbox(MailboxConfig::bounded(500))
    ->withSupervision(SupervisionStrategy::exponentialBackoff(
        initialBackoff: Duration::millis(200),
        maxBackoff: Duration::seconds(30),
    ));
```

## Pipe operator support (PHP 8.5+)

Nexus ships pipe-friendly functions in the `Monadial\Nexus\Core\Actor\Functions` namespace, compatible with the PHP 8.5 pipe operator (`|>`).

```php title="src/Actor/PipeProps.php"
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

## See also

- [Behaviors](./behaviors.md) — defining the behavior that `Props` wraps
- [Mailboxes](./mailboxes.md) — `MailboxConfig` and overflow strategies
- [Supervision](./supervision.md) — `SupervisionStrategy` and directives
