---
sidebar_position: 10
title: Message design
---

# Message design

> *"What makes a good actor message, and what's a trap?"*

Messages are the API of every actor. Bad message design ripples
through every handler, every test, every consumer. The framework
nudges you toward the right shape; this page collects the *why*.

## The rules

> **1. Messages are `final readonly class` value objects.**
> **2. Every message a given actor handles must implement a sealed
> marker interface for that actor's protocol.**
> **3. Reply messages are typed too — never `mixed`, never `array`.**
> **4. Add fields by adding new message classes, not by widening
> existing ones.**

Apply those four and most concurrency, serialisation, and refactoring
bugs in the framework's surface area disappear.

## Make messages immutable

```php
// Right
final readonly class Deposit
{
    public function __construct(public Money $amount) {}
}

// Wrong
final class Deposit
{
    public Money $amount;
}
```

Immutable messages are safe to share between coroutines, safe to
replay through the persistence layer, safe to log without
side-effecting them, safe to put on a queue. The `nexus-psalm`
plugin's `ReadonlyMessageRule` enforces this — `tell()`ing a non-
readonly message is a static error.

For envelopes that carry many small fields, embrace **constructor
property promotion** + **named arguments at the call site**:

```php
final readonly class RecordLedger implements LedgerCommand
{
    public function __construct(
        public LedgerKind $kind,
        public int $amountCents,
    ) {}
}

$actor->tell(new RecordLedger(kind: LedgerKind::Deposit, amountCents: 12345));
```

Named arguments make call sites legible and survive parameter
reordering during refactors.

## Seal each actor's command protocol

Don't let an actor's handler take `object`. Make each protocol an
interface and have every command implement it:

```php
interface LedgerCommand {}
final readonly class RecordLedger    implements LedgerCommand { /* … */ }
final readonly class CorrectEntry    implements LedgerCommand { /* … */ }
final readonly class ArchiveLedger   implements LedgerCommand { /* … */ }
```

The actor's handler types against `LedgerCommand`, not `object`:

```php
static fn(
    ActorContext $ctx,
    LedgerCommand $cmd,
    WalletLedger $ledger,
): EntityEffect => match (true) {
    $cmd instanceof RecordLedger    => self::record($ledger, $cmd),
    $cmd instanceof CorrectEntry    => self::correct($ledger, $cmd),
    $cmd instanceof ArchiveLedger   => self::archive($ledger, $cmd),
};
```

Two payoffs:

1. **Adding a new `LedgerCommand` without a `match` arm is a
   compile-time error** (no `default` branch means Psalm/PHPStan
   catches the missing case).
2. **Sending an unrelated message is a compile-time error** — you
   can't `tell` a `LedgerActor` something that isn't a
   `LedgerCommand`.

This is the closest PHP gets to Erlang-style sealed protocols.
Use it for every actor with more than one command.

## Use enums for closed sets

When a field is "one of N values," reach for a backed enum:

```php
enum LedgerKind: string
{
    case Deposit  = 'deposit';
    case Withdraw = 'withdraw';
}
```

Use it on the message:

```php
final readonly class RecordLedger implements LedgerCommand
{
    public function __construct(
        public LedgerKind $kind,
        public int $amountCents,
    ) {}
}
```

Then `match ($cmd->kind) { LedgerKind::Deposit => …, LedgerKind::Withdraw => … }`
is exhaustive without a `default`. Valinor will decode the wire
string straight onto the enum at the HTTP boundary; Doctrine's
`#[Column(enumType: ...)]` does the same at the storage boundary. The
enum is the canonical shape; everything else maps to it.

## Reply messages are typed too

The single biggest design temptation is to reply with `mixed`,
`array`, or `bool`. Resist.

```php
// Wrong
$ctx->sender()->tell(['accepted' => true, 'balance' => 12345]);

// Right
$ctx->sender()->tell(new DepositResult(
    accepted: true,
    balanceCents: 12345,
));
```

The reply is part of your contract. Type it. Reply DTOs:

- Make the caller's `ask()->await()` site type-check cleanly (assert
  + match on the result type).
- Survive refactors — renaming a field flows through every consumer.
- Serialise safely if you cross a thread or machine boundary.

Group replies by domain. The wallet-app's `Domain/Reply/` holds
`DepositResult`, `WithdrawResult`, `BalanceSnapshot`. Each lives next
to the commands it answers.

## Don't pack envelopes — use the framework's

Avoid:

```php
final readonly class Envelope
{
    public function __construct(
        public string $traceId,
        public string $causationId,
        public object $payload,
    ) {}
}
```

Two problems:
1. You're rebuilding what `Envelope` (`Monadial\Nexus\Core\Mailbox\Envelope`)
   already does, including sender ref and target path.
2. Generic `object` payloads defeat the type system.

Stamp trace context as a *separate small message* the actor reads on
entry, or use `$ctx->stamp($key, $value)` if you want it on the
existing envelope. The system envelope already carries
sender / target / metadata.

## Name messages by intent

Three message-naming conventions, in roughly increasing strength:

1. **Verbs for commands** — `Deposit`, `RecordLedger`, `CloseSession`.
   The actor reads it as an imperative.
2. **Past-tense facts for events** — `MoneyDeposited`, `SessionClosed`,
   `LedgerArchived`. The actor reads it as something that has already
   happened (event-sourced systems).
3. **Noun + Result for replies** — `DepositResult`, `BalanceSnapshot`,
   `SessionState`. The caller reads it as the answer.

Don't mix tenses within a category. A command named `MoneyDeposited`
reads like an event and trips the supervisor's expectations.

## Versioning without pain

Two ways messages need to evolve in production:

**1. Add a new field.** Add it as nullable / defaulted. New senders
fill it; old senders don't. The receiver handles `null`. No version
field needed.

```php
final readonly class RecordLedger implements LedgerCommand
{
    public function __construct(
        public LedgerKind $kind,
        public int $amountCents,
        public ?string $idempotencyKey = null,   // ← added later
    ) {}
}
```

**2. Change the semantics.** Don't. Make a new message class with the
new semantics:

```php
final readonly class RecordLedger    implements LedgerCommand { /* original */ }
final readonly class RecordLedgerV2  implements LedgerCommand { /* new shape */ }
```

The actor handles both. Old callers send V1; new callers send V2;
nobody's wire format breaks. Once every caller is on V2 you can
drop V1.

If you reach for a `$version` field on a single message class, you've
opted into the pain version explicitness is meant to avoid.

## Avoid leaking domain concepts into framework messages

Don't extend or rely on framework system messages
(`PoisonPill`, `Watch`, `Resume`, etc.). They're framework
intrinsics; treat them as private. Build your own protocol on top.

If you find yourself wanting to "extend PoisonPill" to carry an
ownerId, you actually want a domain command `ShutDownWallet(ownerId)`
that the actor's handler translates into `Behavior::stopped()` at the
right time.

## Common smells

| Smell | Fix |
|---|---|
| Handler takes `object`, switches on `instanceof` chain | Sealed marker interface; type against that |
| Reply payload is `array` | Typed Reply DTO |
| `$version` field on a single message class | Two message classes |
| Generic `Envelope { object $payload }` | Use framework `Envelope`; or send the payload directly |
| `match` on enum-like string | Backed enum, exhaustive match |
| Optional fields documented as "set if X" | Two messages: the one with X, the one without |

Each is the version-1 mistake; the fix is the version-2 design that
won't bite you in a year.
