---
sidebar_position: 3
title: How to design actor messages
related:
  - core-concepts/actors
  - core-concepts/behaviors
  - guides/ask-vs-tell
  - guides/single-writer-aggregates
---

# How to design actor messages

Messages are the API of every actor. Their shape determines how safe, testable, and evolvable your actor system is.

## Solution

Four rules cover the vast majority of message design decisions:

1. Messages are `final readonly class` value objects.
2. Every message an actor handles implements a sealed marker interface for that actor's protocol.
3. Reply messages are typed — never `mixed`, never `array`.
4. Add fields by adding new message classes, not by widening existing ones.

```php title="src/Domain/LedgerCommand.php"
interface LedgerCommand {}

final readonly class RecordLedger implements LedgerCommand
{
    public function __construct(
        public LedgerKind $kind,
        public int $amountCents,
        public ?string $idempotencyKey = null,
    ) {}
}

final readonly class CorrectEntry implements LedgerCommand
{
    public function __construct(
        public string $entryId,
        public int $correctedAmountCents,
    ) {}
}

final readonly class DepositResult
{
    public function __construct(
        public bool $accepted,
        public int $balanceCents,
    ) {}
}
```

## How it works

`readonly` prevents mutation after construction, making messages safe to share between coroutines, safe to replay through the persistence layer, and safe to log. The `nexus-psalm` plugin's `ReadonlyMessageRule` makes passing a non-`readonly` message to `tell()` a static error.

Sealing the protocol with a marker interface makes two failure modes compile-time errors rather than runtime surprises: sending an unrelated message to an actor, and adding a new command without a matching handler arm.

## Variations

### Using enums for closed sets of values

When a field is "one of N values," use a backed enum rather than a string constant or class hierarchy:

```php title="src/Domain/LedgerKind.php"
enum LedgerKind: string
{
    case Deposit  = 'deposit';
    case Withdraw = 'withdraw';
}
```

A `match ($cmd->kind)` without a `default` branch is exhaustive. Psalm flags any unhandled case. Valinor decodes the wire string onto the enum at the HTTP boundary; Doctrine's `#[Column(enumType: ...)]` does the same at the storage boundary.

### Typed reply messages

The single most common design mistake is replying with `array` or `bool`. Type every reply:

```php title="src/Domain/Reply/DepositResult.php"
// Wrong — destroys type information the caller needs
$ctx->sender()->tell(['accepted' => true, 'balance' => 12345]);

// Right — reply is part of the contract; type it
$ctx->sender()->tell(new DepositResult(
    accepted: true,
    balanceCents: 12345,
));
```

Typed replies make the `ask()->await()` site type-check cleanly, survive field renames during refactors, and serialise safely when crossing thread or machine boundaries. Group replies by domain: a `Domain/Reply/` directory holding `DepositResult`, `WithdrawResult`, and `BalanceSnapshot` is easier to navigate than scattered ad-hoc classes.

### Naming by intent

Three naming conventions, applied consistently:

- **Verbs for commands** — `Deposit`, `RecordLedger`, `CloseSession`. The actor reads the message as an instruction.
- **Past-tense facts for events** — `MoneyDeposited`, `SessionClosed`. The actor reads it as something that already happened (event-sourced systems).
- **Noun + Result for replies** — `DepositResult`, `BalanceSnapshot`, `SessionState`. The caller reads it as the answer.

Don't mix tenses within a category. A command named `MoneyDeposited` reads like a domain event and misleads every reader.

### Sealed handler with exhaustive match

Once the protocol is sealed, the handler can drop the `object` type and use an exhaustive `match`:

```php title="src/Actor/LedgerActor.php"
static fn(
    ActorContext $ctx,
    LedgerCommand $cmd,
    WalletLedger $ledger,
): EntityEffect => match (true) {
    $cmd instanceof RecordLedger  => self::record($ledger, $cmd),
    $cmd instanceof CorrectEntry  => self::correct($ledger, $cmd),
    $cmd instanceof ArchiveLedger => self::archive($ledger, $cmd),
};
```

No `default` branch means Psalm catches any new `LedgerCommand` that lacks a handler arm at static analysis time.

## Caveats

- Do not build a custom `Envelope` class wrapping `object $payload`. You are recreating what `Monadial\Nexus\Core\Mailbox\Envelope` already provides, including sender ref, target path, and metadata. Use `$ctx->stamp($key, $value)` for trace context instead.
- Do not extend or rely on framework system messages (`PoisonPill`, `Watch`, `Resume`). They are framework internals. Build your own protocol on top, and translate to framework signals inside the handler when needed.
- Avoid a `$version` field on a single message class. When semantics change, make a new message class. Run both in parallel until every caller has migrated, then drop the old one.

## Common smells

| Smell | Fix |
|---|---|
| Handler takes `object`, switches on `instanceof` chain | Sealed marker interface; type against it |
| Reply payload is `array` | Typed reply DTO |
| `$version` field on a single class | Two message classes |
| Custom `Envelope { object $payload }` | Use `$ctx->stamp()` for metadata; send payload directly |
| `match` on string constant | Backed enum with exhaustive match |
| Optional fields documented as "set if X" | Two message classes: the one with X and the one without |

## Related

- [Ask vs tell](ask-vs-tell.md)
- [Single-writer aggregates](single-writer-aggregates.md)
- [Actors](../core-concepts/actors.md)
