---
title: Props
sidebar_position: 4
related:
  - core-concepts/props
  - core-concepts/actors
  - reference/classes/behavior
  - reference/classes/actor-system
---

# Props

Immutable spawn configuration for an actor.

## What it does

`Props<T>` bundles three things: how to create the actor's behavior, the mailbox
capacity policy, and the supervision strategy override. You pass a `Props` value to
`ActorSystem::spawn()` or `ActorContext::spawn()` to create a new actor. The four
static factory methods (`fromBehavior`, `fromFactory`, `fromStatefulFactory`,
`fromContainer`) cover every actor definition style supported by Nexus: closure-based,
class-based, stateful class-based, and PSR-11 container-resolved. Mailbox and
supervision defaults are sensible out of the box — only override them when your actor
has specific capacity or fault-tolerance requirements.

## Example

```php title="src/ActorBootstrap.php"
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\Runtime\Mailbox\OverflowStrategy;

// Closure-based (simplest)
$props = Props::fromBehavior(
    Behavior::receive(fn($ctx, $msg) => Behavior::same()),
);

// Class-based — a fresh instance is created per spawn
$props = Props::fromFactory(fn() => new OrderActor($repository));

// Class-based from a PSR-11 DI container
$props = Props::fromContainer($container, PaymentActor::class);

// Override mailbox and supervision
$props = Props::fromFactory(fn() => new WorkerActor())
    ->withMailbox(MailboxConfig::bounded(256, OverflowStrategy::DropNewest))
    ->withSupervision(SupervisionStrategy::oneForOne(maxRetries: 3));

$ref = $system->spawn($props, 'worker');
```

## Key methods

- `Props::fromBehavior(Behavior<T> $behavior): Props<T>` — wrap a pre-built `Behavior` value.
- `Props::fromFactory(Closure $factory): Props<T>` — factory that returns an `ActorHandler<T>` (or `AbstractActor`); called once per spawn.
- `Props::fromStatefulFactory(Closure $factory): Props<T>` — factory that returns a `StatefulActorHandler<T, S>`; state is managed by the runtime.
- `Props::fromContainer(ContainerInterface $c, string $class): Props<T>` — resolves the actor class from a PSR-11 container.
- `->withMailbox(MailboxConfig $config): self` — override the mailbox (default: unbounded).
- `->withSupervision(SupervisionStrategy $strategy): self` — override the supervision strategy (default: inherited from parent).

## Full API reference

[Full method list and class hierarchy](https://api.nexusactors.com/classes/Monadial-Nexus-Core-Actor-Props.html)

## See also

- [Props concept](../../core-concepts/props) — design rationale and examples
- [Behavior](behavior) — the behavior factory methods used inside `Props::fromBehavior()`
- [ActorSystem](actor-system) — `spawn(Props, string)` is the top-level spawn entry point
- [Actors concept](../../core-concepts/actors) — the actor hierarchy and supervision trees
