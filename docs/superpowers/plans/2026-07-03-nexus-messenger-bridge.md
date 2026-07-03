# Nexus ↔ Symfony Messenger Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the new `packages/nexus-messenger` package — a two-way bridge (producer + consumer) between Nexus actors and standalone `symfony/messenger` transports, per the approved spec `docs/superpowers/specs/2026-07-01-nexus-messenger-bridge-design.md`.

**Architecture:** Producer side wraps a Messenger `SenderInterface` behind `MessengerActorRef` (location-transparent `tell()`) and an explicit `MessengerGateway`. Consumer side is a supervised `ReceiverActor` that polls a `ReceiverInterface`, routes each envelope via a pluggable `MessageRouter`, and acks only on accepted enqueue (a tiny new `BackpressureCapable` seam in nexus-core exposes the mailbox `EnqueueResult`). A `LifecycleWatchdog` actor handles worker recycling (memory/time/message-count → graceful `ActorSystem::shutdown()`).

**Tech Stack:** PHP 8.5, symfony/messenger ^8.0 (root) / `^7.4 || ^8.0` (package), nexus-core, nexus-runtime, nexus-serialization, PHPUnit 13, Psalm level 1, Deptrac, Docusaurus docs.

## Global Constraints

- **All commands run through Docker** — `docker compose exec php …` or `make` targets. Never run `php`, `composer`, or `vendor/bin/*` on the host.
- **Never add `Co-Authored-By: Claude` (or any Claude attribution / session link) to commits.**
- Work on branch `feat/nexus-messenger` off `main`, in the main checkout. **Do NOT use a git worktree** (GrumPHP hooks are broken in Docker worktrees on this machine).
- GrumPHP pre-commit runs php-cs-fixer, phpcs, psalm, and the full `unit` test suite on **every commit** — all must pass. Before each commit run `make cs-fix` then `make phpcs` and `make psalm`.
- Package composer name: **`nexus-actors/messenger`** (vendor prefix is `nexus-actors/`, not `monadial/`). Namespace `Monadial\Nexus\Messenger`, PSR-4 from `src/`. Inter-package deps use constraint **`"dev-main"`**. PHP floor `>=8.5.7`, PHPUnit `^13.0`.
- Code style: `declare(strict_types=1)` everywhere; all classes `final`; value objects `readonly`; PER-CS2.0 + Slevomat — string-keyed array literals **sorted alphabetically**, ternaries **multi-line**, **blank line before** `if`/`for`/`foreach`/`while`/`switch`/`try`, ordered imports (class, function, const — each alphabetical), trailing commas in multiline contexts.
- Psalm level 1: public API carries generics docblocks (`ActorRef<object>`, `Behavior<object>`, `@psalm-api` on public surface).
- `nexus-core` must not gain external dependencies (Deptrac-enforced). The Task 2 core change adds an interface + method only.
- Unit tests: `packages/nexus-messenger/tests/Unit/` (namespace `Monadial\Nexus\Messenger\Tests\Unit`), `#[CoversClass(...)]` + `#[Test]` attributes. Integration tests: `tests/Integration/Messenger/` (namespace `Monadial\Nexus\Tests\Integration\Messenger`), Fiber pattern: boot `FiberRuntime` + `ActorSystem::create`, `$runtime->scheduleOnce(...)` a `$system->shutdown(Duration::seconds(1))`, then `$system->run()` and assert captured state.

## Deviations from the spec (discovered against the real codebase)

1. **`ask()` signature** — the spec shows `ask(callable, Duration)`; the real interface is `ask(object $message, Duration $timeout): Future`. `MessengerActorRef::ask()` implements the real signature and throws.
2. **`UnsupportedOperation` does not exist** — we create `Monadial\Nexus\Messenger\Exception\UnsupportedOperationException extends NexusException`.
3. **`EnqueueResult` is not observable via `ActorRef::tell()` (returns void, swallows the result)** — the spec's ack/no-ack loop (§5.1) requires a seam: new core interface `BackpressureCapable { offer(object): EnqueueResult }`, implemented by `LocalActorRef`. Non-capable refs are told + acked (documented).
4. **Stamp preservation in `NexusMessengerSerializer` is limited to the bridge's own stamps** (`SourceActorPathStamp`, `TargetActorPathStamp`) via plain string headers. Arbitrary stamp round-trip would need `serialize()`/`unserialize()` (rejected: the repo just hardened against unsafe unserialize) — documented v1 limitation.
5. **`NexusMessengerBundleWiring` renamed to `MessengerBridge`** (spec allowed confirming names at implementation time; "Bundle" is misleading — there is no Symfony bundle).
6. **`Duration`, `EnqueueResult`, `Mailbox` live in `nexus-runtime`** (`Monadial\Nexus\Runtime\...`), not nexus-core; the package requires `nexus-actors/runtime` as the spec anticipated.
7. **Clock** — there is no Nexus Clock interface; `ActorSystem::clock()` returns PSR-20 `Psr\Clock\ClockInterface`. The watchdog measures uptime via `->now()->getTimestamp()` (second precision — fine for recycling limits).
8. **Addition beyond the spec (user request 2026-07-03):** horizontal scaling — `MessengerBridge::spawnReceivers()` spawns N competing `ReceiverActor`s over one transport (e.g. 10 messenger workers in-process), and the docs cover the N-process scale-out model and target-actor pooling (Task 8 scaling note, Tasks 11–12).

---

### Task 1: Branch, package scaffolding, monorepo wiring, stamps

**Files:**
- Create: `packages/nexus-messenger/composer.json`
- Create: `packages/nexus-messenger/src/Stamp/SourceActorPathStamp.php`
- Create: `packages/nexus-messenger/src/Stamp/TargetActorPathStamp.php`
- Test: `packages/nexus-messenger/tests/Unit/Stamp/StampsTest.php`
- Modify: `composer.json` (root — autoload, autoload-dev, require symfony/messenger)
- Modify: `phpunit.xml` (unit suite dir, integration suite, coverage source)
- Modify: `deptrac.yaml` (Messenger layer + ruleset)
- Modify: `Makefile` (test-messenger target, `.PHONY`, aggregate `test`)

**Interfaces:**
- Produces: `Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp` and `TargetActorPathStamp`, both `final readonly`, implementing `Symfony\Component\Messenger\Stamp\StampInterface`, single ctor-promoted `public string $path`. All later tasks build on this package skeleton.

- [ ] **Step 1: Create the branch**

```bash
git checkout main && git pull origin main && git checkout -b feat/nexus-messenger
```

- [ ] **Step 2: Create `packages/nexus-messenger/composer.json`**

```json
{
    "name": "nexus-actors/messenger",
    "description": "Nexus messenger — two-way bridge between Nexus actors and Symfony Messenger transports: producer refs, supervised receiver actor, and worker recycling.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/core": "dev-main",
        "nexus-actors/runtime": "dev-main",
        "nexus-actors/serialization": "dev-main",
        "symfony/messenger": "^7.4 || ^8.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Messenger\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Messenger\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 3: Wire root `composer.json`**

Add to `autoload.psr-4` (keep the existing ordering style, next to the other `Monadial\Nexus\*` entries):

```json
"Monadial\\Nexus\\Messenger\\": "packages/nexus-messenger/src/",
```

Add to `autoload-dev.psr-4`:

```json
"Monadial\\Nexus\\Messenger\\Tests\\": "packages/nexus-messenger/tests/",
```

Add to root `require` (alphabetical position among `symfony/*`):

```json
"symfony/messenger": "^8.0",
```

- [ ] **Step 4: Install**

```bash
docker compose exec php composer update symfony/messenger
docker compose exec php composer validate
```

Expected: symfony/messenger 8.x installed into `vendor/symfony/messenger`; validate passes (lock warning acceptable only if pre-existing).

- [ ] **Step 5: Wire `phpunit.xml`**

Inside `<testsuite name="unit">` add (after the cluster entry):

```xml
<directory>packages/nexus-messenger/tests/Unit</directory>
```

Add a new integration suite next to `integration-serialization`:

```xml
<testsuite name="integration-messenger">
    <directory>tests/Integration/Messenger</directory>
</testsuite>
```

Add to `<source><include>`:

```xml
<directory>packages/nexus-messenger/src</directory>
```

Create the integration dir now so the suite resolves: `mkdir -p tests/Integration/Messenger`.

- [ ] **Step 6: Wire `deptrac.yaml`**

Add a layer (mirroring the Cluster block):

```yaml
- name: Messenger
  collectors:
    - type: directory
      value: packages/nexus-messenger/src/.*
```

Add to `ruleset:`:

```yaml
Messenger:
  - Core
  - Runtime
  - Serialization
```

- [ ] **Step 7: Wire `Makefile`**

Add target (mirroring `test-serialization`), add `test-messenger` to the `.PHONY` line, and add the suite to the aggregate `test` target's enumerated suites:

```make
test-messenger: ## Messenger integration tests
	$(DC) vendor/bin/phpunit --testsuite=integration-messenger
```

- [ ] **Step 8: Write the failing stamp test**

`packages/nexus-messenger/tests/Unit/Stamp/StampsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Stamp;

use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[CoversClass(SourceActorPathStamp::class)]
#[CoversClass(TargetActorPathStamp::class)]
final class StampsTest extends TestCase
{
    #[Test]
    public function sourceStampCarriesPathAndIsAMessengerStamp(): void
    {
        $stamp = new SourceActorPathStamp('/user/emitter');

        self::assertSame('/user/emitter', $stamp->path);
        self::assertInstanceOf(StampInterface::class, $stamp);
    }

    #[Test]
    public function targetStampCarriesPathAndIsAMessengerStamp(): void
    {
        $stamp = new TargetActorPathStamp('/user/orders');

        self::assertSame('/user/orders', $stamp->path);
        self::assertInstanceOf(StampInterface::class, $stamp);
    }
}
```

- [ ] **Step 9: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Stamp/StampsTest.php
```

Expected: FAIL — class `SourceActorPathStamp` not found.

- [ ] **Step 10: Implement the stamps**

`packages/nexus-messenger/src/Stamp/SourceActorPathStamp.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Provenance stamp: the path of the Nexus actor that produced an egress message.
 *
 * Attached by MessengerActorRef when a source path is configured, and
 * round-tripped by NexusMessengerSerializer as the X-Nexus-Source-Path header.
 *
 * @psalm-api
 */
final readonly class SourceActorPathStamp implements StampInterface
{
    public function __construct(public string $path)
    {
    }
}
```

`packages/nexus-messenger/src/Stamp/TargetActorPathStamp.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Routing stamp: the path of the Nexus actor an inbound message is addressed to.
 *
 * Read by StampMessageRouter. Reserved as the seam for a future cluster
 * transport over Messenger; round-tripped as the X-Nexus-Target-Path header.
 *
 * @psalm-api
 */
final readonly class TargetActorPathStamp implements StampInterface
{
    public function __construct(public string $path)
    {
    }
}
```

- [ ] **Step 11: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Stamp/StampsTest.php
```

Expected: PASS (2 tests).

- [ ] **Step 12: Verify wiring and commit**

```bash
make cs-fix && make phpcs && make psalm
docker compose exec php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac --config-file=deptrac.yaml
git add packages/nexus-messenger composer.json composer.lock phpunit.xml deptrac.yaml Makefile
git commit -m "feat(messenger): scaffold nexus-messenger package with Messenger stamps"
```

Expected: deptrac reports no violations; GrumPHP hook passes.

---

### Task 2: Core seam — `BackpressureCapable` + `LocalActorRef::offer()`

**Files:**
- Create: `packages/nexus-core/src/Actor/BackpressureCapable.php`
- Modify: `packages/nexus-core/src/Actor/LocalActorRef.php`
- Test: `packages/nexus-core/tests/Unit/Actor/LocalActorRefOfferTest.php`

**Interfaces:**
- Produces: `Monadial\Nexus\Core\Actor\BackpressureCapable` with `offer(object $message): EnqueueResult` (`Monadial\Nexus\Runtime\Mailbox\EnqueueResult`: cases `Accepted|Dropped|Backpressured`). `LocalActorRef implements ActorRef, BackpressureCapable`. Task 6's `ReceiverActor` type-checks targets against this interface.

- [ ] **Step 1: Write the failing test**

First read `packages/nexus-core/tests/Unit/Actor/` for an existing `LocalActorRef` test to copy its exact fixture construction (`LocalActorRef` ctor needs `ActorPath`, `Mailbox`, alive-checker `Closure`, `Runtime`, `Observability` — reuse the existing helper/support classes such as `TestMailbox`/`TestRuntime` from `packages/nexus-core/tests/Support/` and the observability no-op the existing tests use). Then create `packages/nexus-core/tests/Unit/Actor/LocalActorRefOfferTest.php` with these test methods (adapt only the fixture construction lines to match the existing tests):

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Core\Actor\LocalActorRef;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalActorRef::class)]
final class LocalActorRefOfferTest extends TestCase
{
    #[Test]
    public function offerReturnsAcceptedWhenMailboxAccepts(): void
    {
        $ref = $this->makeRef(); // build exactly like the existing LocalActorRef tests

        self::assertInstanceOf(BackpressureCapable::class, $ref);
        self::assertSame(EnqueueResult::Accepted, $ref->offer(new \stdClass()));
    }

    #[Test]
    public function offerReturnsDroppedWhenMailboxIsClosed(): void
    {
        [$ref, $mailbox] = $this->makeRefWithMailbox();
        $mailbox->close();

        self::assertSame(EnqueueResult::Dropped, $ref->offer(new \stdClass()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-core/tests/Unit/Actor/LocalActorRefOfferTest.php
```

Expected: FAIL — `BackpressureCapable` not found / `offer()` undefined.

- [ ] **Step 3: Implement the interface**

`packages/nexus-core/src/Actor/BackpressureCapable.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;

/**
 * An ActorRef whose sends can report mailbox admission.
 *
 * tell() is fire-and-forget by contract and swallows the mailbox result.
 * Integrations that need delivery feedback to propagate backpressure to an
 * upstream source (for example, a message broker that should not be acked
 * until the message is safely enqueued) use offer() instead.
 *
 * @psalm-api
 */
interface BackpressureCapable
{
    /**
     * Enqueue a message and report the mailbox admission result.
     *
     * Returns Dropped when the mailbox is already closed.
     */
    public function offer(object $message): EnqueueResult;
}
```

- [ ] **Step 4: Implement `offer()` on `LocalActorRef`**

In `packages/nexus-core/src/Actor/LocalActorRef.php`: add `BackpressureCapable` to the `implements` clause, add the `EnqueueResult` import, and add (next to `tell()`):

```php
public function offer(object $message): EnqueueResult
{
    try {
        return $this->mailbox->enqueue($this->envelopeFor($message, ActorPath::root()));
    } catch (MailboxClosedException) {
        return EnqueueResult::Dropped;
    }
}
```

Then make `tell()` delegate (DRY, behavior identical):

```php
public function tell(object $message): void
{
    $_ = $this->offer($message);
}
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-core/tests/Unit/Actor/LocalActorRefOfferTest.php
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: new tests PASS; full unit suite stays green.

- [ ] **Step 6: Commit**

```bash
make cs-fix && make phpcs && make psalm
git add packages/nexus-core
git commit -m "feat(core): BackpressureCapable seam exposing mailbox EnqueueResult on LocalActorRef"
```

---

### Task 3: Producer — `UnsupportedOperationException`, `MessengerActorRef`, `MessengerGateway`

**Files:**
- Create: `packages/nexus-messenger/src/Exception/UnsupportedOperationException.php`
- Create: `packages/nexus-messenger/src/Producer/MessengerActorRef.php`
- Create: `packages/nexus-messenger/src/Producer/MessengerGateway.php`
- Create: `packages/nexus-messenger/tests/Support/RecordingSender.php`
- Test: `packages/nexus-messenger/tests/Unit/Producer/MessengerActorRefTest.php`
- Test: `packages/nexus-messenger/tests/Unit/Producer/MessengerGatewayTest.php`

**Interfaces:**
- Consumes: `SourceActorPathStamp` (Task 1).
- Produces:
  - `MessengerActorRef` — `final readonly`, `@template T of object`, `implements ActorRef` (`@template-implements ActorRef<T>`). Ctor `(SenderInterface $sender, string $senderName, ?ActorPath $sourcePath = null)`. `tell()` sends a Symfony `Envelope`; `ask()` throws `UnsupportedOperationException`; `path()` = `/messenger/<senderName>`; `isAlive()` = `true`.
  - `MessengerGateway` — ctor `(SenderInterface $sender)`, `publish(object $message, array $stamps = []): void`.
  - `UnsupportedOperationException extends NexusException` (`Monadial\Nexus\Messenger\Exception`).
  - Test double `Monadial\Nexus\Messenger\Tests\Support\RecordingSender implements SenderInterface` with `public array $sent`.

- [ ] **Step 1: Create the shared test double**

`packages/nexus-messenger/tests/Support/RecordingSender.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class RecordingSender implements SenderInterface
{
    /** @var list<Envelope> */
    public array $sent = [];

    public function send(Envelope $envelope): Envelope
    {
        $this->sent[] = $envelope;

        return $envelope;
    }
}
```

- [ ] **Step 2: Write the failing ref test**

`packages/nexus-messenger/tests/Unit/Producer/MessengerActorRefTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Producer;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessengerActorRef::class)]
final class MessengerActorRefTest extends TestCase
{
    #[Test]
    public function tellWrapsTheMessageInAnEnvelopeAndSends(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerActorRef($sender, 'orders-out');
        $message = new \stdClass();

        $ref->tell($message);

        self::assertCount(1, $sender->sent);
        self::assertSame($message, $sender->sent[0]->getMessage());
        self::assertNull($sender->sent[0]->last(SourceActorPathStamp::class));
    }

    #[Test]
    public function tellStampsTheSourcePathWhenConfigured(): void
    {
        $sender = new RecordingSender();
        $ref = new MessengerActorRef($sender, 'orders-out', ActorPath::fromString('/user/emitter'));

        $ref->tell(new \stdClass());

        $stamp = $sender->sent[0]->last(SourceActorPathStamp::class);

        self::assertInstanceOf(SourceActorPathStamp::class, $stamp);
        self::assertSame('/user/emitter', $stamp->path);
    }

    #[Test]
    public function askThrowsUnsupportedOperation(): void
    {
        $ref = new MessengerActorRef(new RecordingSender(), 'orders-out');

        $this->expectException(UnsupportedOperationException::class);

        $ref->ask(new \stdClass(), Duration::seconds(1));
    }

    #[Test]
    public function pathIsSyntheticAndRefIsAlwaysAlive(): void
    {
        $ref = new MessengerActorRef(new RecordingSender(), 'orders-out');

        self::assertSame('/messenger/orders-out', (string) $ref->path());
        self::assertTrue($ref->isAlive());
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Producer/MessengerActorRefTest.php
```

Expected: FAIL — `MessengerActorRef` not found.

- [ ] **Step 4: Implement exception and ref**

`packages/nexus-messenger/src/Exception/UnsupportedOperationException.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Exception;

use Monadial\Nexus\Core\Exception\NexusException;

/**
 * Thrown when a Messenger-backed ref is asked to do something the broker
 * boundary cannot support in v1 (currently: ask() request/reply).
 *
 * @psalm-api
 */
final class UnsupportedOperationException extends NexusException
{
}
```

`packages/nexus-messenger/src/Producer/MessengerActorRef.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Producer;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * ActorRef backed by a Symfony Messenger sender — the location-transparent
 * egress API. Actor code telling this ref is byte-identical to a local send;
 * the message leaves the process through the configured transport.
 *
 * ask() is not supported in v1: broker request/reply requires correlation
 * stamps and a reply transport.
 *
 * Example:
 * ```php
 * $ref = new MessengerActorRef($transport, 'orders-out');
 * $ref->tell(new OrderPlaced('A-42'));
 * ```
 *
 * @psalm-api
 *
 * @template T of object
 * @template-implements ActorRef<T>
 */
final readonly class MessengerActorRef implements ActorRef
{
    public function __construct(
        private SenderInterface $sender,
        private string $senderName,
        private ?ActorPath $sourcePath = null,
    ) {
    }

    public function tell(object $message): void
    {
        $envelope = new Envelope($message);

        if ($this->sourcePath !== null) {
            $envelope = $envelope->with(new SourceActorPathStamp((string) $this->sourcePath));
        }

        $this->sender->send($envelope);
    }

    public function ask(object $message, Duration $timeout): Future
    {
        throw new UnsupportedOperationException(
            'ask() is not supported on MessengerActorRef; broker request/reply is deferred beyond v1.',
        );
    }

    public function path(): ActorPath
    {
        return ActorPath::root()->child('messenger')->child($this->senderName);
    }

    public function isAlive(): bool
    {
        return true;
    }
}
```

Note: if `(string) ActorPath::root()->child('messenger')->child('orders-out')` does not render `/messenger/orders-out`, check `ActorPath::__toString()` and adjust the test expectation to the actual canonical rendering — the path just has to be stable and name the sender.

- [ ] **Step 5: Run test to verify it passes**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Producer/MessengerActorRefTest.php
```

Expected: PASS (4 tests).

- [ ] **Step 6: Write the failing gateway test**

`packages/nexus-messenger/tests/Unit/Producer/MessengerGatewayTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Producer;

use Monadial\Nexus\Messenger\Producer\MessengerGateway;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Messenger\Tests\Support\RecordingSender;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessengerGateway::class)]
final class MessengerGatewayTest extends TestCase
{
    #[Test]
    public function publishSendsTheMessageWithGivenStamps(): void
    {
        $sender = new RecordingSender();
        $gateway = new MessengerGateway($sender);
        $message = new \stdClass();
        $stamp = new TargetActorPathStamp('/user/orders');

        $gateway->publish($message, [$stamp]);

        self::assertCount(1, $sender->sent);
        self::assertSame($message, $sender->sent[0]->getMessage());
        self::assertSame($stamp, $sender->sent[0]->last(TargetActorPathStamp::class));
    }
}
```

- [ ] **Step 7: Run it to verify it fails, then implement the gateway**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Producer/MessengerGatewayTest.php
```

`packages/nexus-messenger/src/Producer/MessengerGateway.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Producer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Explicit egress service for code that wants to be deliberate that a message
 * leaves the actor system. Same underlying sender as MessengerActorRef —
 * choose this API when "this goes to a broker" should be visible at the call
 * site.
 *
 * @psalm-api
 */
final readonly class MessengerGateway
{
    public function __construct(private SenderInterface $sender)
    {
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function publish(object $message, array $stamps = []): void
    {
        $this->sender->send(new Envelope($message, $stamps));
    }
}
```

- [ ] **Step 8: Run both test files, verify PASS, commit**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit
make cs-fix && make phpcs && make psalm
git add packages/nexus-messenger
git commit -m "feat(messenger): producer API — MessengerActorRef and MessengerGateway"
```

---

### Task 4: Routing — `MessageRouter`, `MapMessageRouter`, `StampMessageRouter`

**Files:**
- Create: `packages/nexus-messenger/src/Routing/MessageRouter.php`
- Create: `packages/nexus-messenger/src/Routing/MapMessageRouter.php`
- Create: `packages/nexus-messenger/src/Routing/StampMessageRouter.php`
- Test: `packages/nexus-messenger/tests/Unit/Routing/MapMessageRouterTest.php`
- Test: `packages/nexus-messenger/tests/Unit/Routing/StampMessageRouterTest.php`

**Interfaces:**
- Consumes: `TargetActorPathStamp` (Task 1).
- Produces: `MessageRouter::route(object $message, Envelope $envelope): ?ActorRef` (Symfony `Envelope`; `null` = unroutable). `MapMessageRouter::__construct(array<class-string, ActorRef<object>> $routes)`. `StampMessageRouter::__construct(array<string, ActorRef<object>> $registry)` keyed by actor-path string. Task 6 consumes `MessageRouter`.

- [ ] **Step 1: Write the failing tests**

Tests need a stub `ActorRef`; use `Monadial\Nexus\Core\Actor\DeadLetterRef` (a concrete, dependency-free `ActorRef`) as the routed target in tests.

`packages/nexus-messenger/tests/Unit/Routing/MapMessageRouterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Routing;

use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(MapMessageRouter::class)]
final class MapMessageRouterTest extends TestCase
{
    #[Test]
    public function routesByExactMessageClass(): void
    {
        $target = new DeadLetterRef();
        $router = new MapMessageRouter([\stdClass::class => $target]);
        $message = new \stdClass();

        self::assertSame($target, $router->route($message, new Envelope($message)));
    }

    #[Test]
    public function returnsNullForUnregisteredClass(): void
    {
        $router = new MapMessageRouter([]);
        $message = new \stdClass();

        self::assertNull($router->route($message, new Envelope($message)));
    }
}
```

`packages/nexus-messenger/tests/Unit/Routing/StampMessageRouterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Routing;

use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Messenger\Routing\StampMessageRouter;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(StampMessageRouter::class)]
final class StampMessageRouterTest extends TestCase
{
    #[Test]
    public function routesByTargetPathStamp(): void
    {
        $target = new DeadLetterRef();
        $router = new StampMessageRouter(['/user/orders' => $target]);
        $message = new \stdClass();
        $envelope = new Envelope($message, [new TargetActorPathStamp('/user/orders')]);

        self::assertSame($target, $router->route($message, $envelope));
    }

    #[Test]
    public function returnsNullWhenStampIsMissing(): void
    {
        $router = new StampMessageRouter(['/user/orders' => new DeadLetterRef()]);
        $message = new \stdClass();

        self::assertNull($router->route($message, new Envelope($message)));
    }

    #[Test]
    public function returnsNullWhenPathIsUnknown(): void
    {
        $router = new StampMessageRouter([]);
        $message = new \stdClass();
        $envelope = new Envelope($message, [new TargetActorPathStamp('/user/unknown')]);

        self::assertNull($router->route($message, $envelope));
    }
}
```

- [ ] **Step 2: Run to verify FAIL, then implement**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Routing
```

`packages/nexus-messenger/src/Routing/MessageRouter.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Core\Actor\ActorRef;
use Symfony\Component\Messenger\Envelope;

/**
 * Resolves an inbound Messenger envelope to the Nexus actor that should
 * receive its message. Returning null marks the message unroutable; the
 * ReceiverActor then applies its UnroutablePolicy (reject or dead-letters).
 *
 * @psalm-api
 */
interface MessageRouter
{
    /**
     * @return ActorRef<object>|null
     */
    public function route(object $message, Envelope $envelope): ?ActorRef;
}
```

`packages/nexus-messenger/src/Routing/MapMessageRouter.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Core\Actor\ActorRef;
use Symfony\Component\Messenger\Envelope;

/**
 * Default router: exact message class → registered ActorRef.
 *
 * Example:
 * ```php
 * $router = new MapMessageRouter([OrderPlaced::class => $ordersRef]);
 * ```
 *
 * @psalm-api
 */
final readonly class MapMessageRouter implements MessageRouter
{
    /**
     * @param array<class-string, ActorRef<object>> $routes
     */
    public function __construct(private array $routes)
    {
    }

    public function route(object $message, Envelope $envelope): ?ActorRef
    {
        return $this->routes[$message::class] ?? null;
    }
}
```

`packages/nexus-messenger/src/Routing/StampMessageRouter.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Symfony\Component\Messenger\Envelope;

/**
 * Cluster-seam router: resolves the TargetActorPathStamp on the envelope
 * against a path → ActorRef registry. Messages without the stamp, or with a
 * path not present in the registry, are unroutable.
 *
 * @psalm-api
 */
final readonly class StampMessageRouter implements MessageRouter
{
    /**
     * @param array<string, ActorRef<object>> $registry keyed by actor-path string
     */
    public function __construct(private array $registry)
    {
    }

    public function route(object $message, Envelope $envelope): ?ActorRef
    {
        $stamp = $envelope->last(TargetActorPathStamp::class);

        if (!$stamp instanceof TargetActorPathStamp) {
            return null;
        }

        return $this->registry[$stamp->path] ?? null;
    }
}
```

- [ ] **Step 3: Run to verify PASS, commit**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit
make cs-fix && make phpcs && make psalm
git add packages/nexus-messenger
git commit -m "feat(messenger): pluggable MessageRouter with map and stamp implementations"
```

---

### Task 5: `NexusMessengerSerializer`

**Files:**
- Create: `packages/nexus-messenger/src/Serialization/NexusMessengerSerializer.php`
- Create: `packages/nexus-messenger/tests/Unit/Fixture/Greeting.php`
- Test: `packages/nexus-messenger/tests/Unit/Serialization/NexusMessengerSerializerTest.php`

**Interfaces:**
- Consumes: `Monadial\Nexus\Serialization\MessageSerializer` (`serialize(object): string`, `deserialize(string, string $type): object`), `TypeRegistry` (`nameForClass(string): ?string`, `classForName(string): ?string`, `registerFromAttribute(string): void`), `PhpNativeSerializer(?array $allowedClasses = null)`, `#[MessageType(string $name)]`; stamps from Task 1.
- Produces: `NexusMessengerSerializer implements Symfony\Component\Messenger\Transport\Serialization\SerializerInterface` — ctor `(MessageSerializer $messages, TypeRegistry $types)`; `encode(Envelope): array{body: string, headers: array<string, string>}`; `decode(array): Envelope`; throws Symfony `MessageDecodingFailedException` on malformed input.

- [ ] **Step 1: Create the message fixture**

`packages/nexus-messenger/tests/Unit/Fixture/Greeting.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Fixture;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('greeting')]
final readonly class Greeting
{
    public function __construct(public string $text)
    {
    }
}
```

- [ ] **Step 2: Write the failing test**

`packages/nexus-messenger/tests/Unit/Serialization/NexusMessengerSerializerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Serialization;

use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Messenger\Tests\Unit\Fixture\Greeting;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;

#[CoversClass(NexusMessengerSerializer::class)]
final class NexusMessengerSerializerTest extends TestCase
{
    private NexusMessengerSerializer $serializer;

    protected function setUp(): void
    {
        $registry = new TypeRegistry();
        $registry->registerFromAttribute(Greeting::class);
        $this->serializer = new NexusMessengerSerializer(
            new PhpNativeSerializer([Greeting::class]),
            $registry,
        );
    }

    #[Test]
    public function encodeProducesBodyAndRegisteredTypeHeader(): void
    {
        $encoded = $this->serializer->encode(new Envelope(new Greeting('hello')));

        self::assertArrayHasKey('body', $encoded);
        self::assertSame('greeting', $encoded['headers']['type']);
    }

    #[Test]
    public function roundTripPreservesMessageAndBridgeStamps(): void
    {
        $envelope = new Envelope(new Greeting('hello'), [
            new SourceActorPathStamp('/user/emitter'),
            new TargetActorPathStamp('/user/orders'),
        ]);

        $decoded = $this->serializer->decode($this->serializer->encode($envelope));
        $message = $decoded->getMessage();

        self::assertInstanceOf(Greeting::class, $message);
        self::assertSame('hello', $message->text);

        $source = $decoded->last(SourceActorPathStamp::class);
        $target = $decoded->last(TargetActorPathStamp::class);

        self::assertInstanceOf(SourceActorPathStamp::class, $source);
        self::assertSame('/user/emitter', $source->path);
        self::assertInstanceOf(TargetActorPathStamp::class, $target);
        self::assertSame('/user/orders', $target->path);
    }

    #[Test]
    public function encodeFallsBackToFqcnWhenTypeIsUnregistered(): void
    {
        $serializer = new NexusMessengerSerializer(
            new PhpNativeSerializer([Greeting::class]),
            new TypeRegistry(),
        );

        $encoded = $serializer->encode(new Envelope(new Greeting('hello')));

        self::assertSame(Greeting::class, $encoded['headers']['type']);
        self::assertInstanceOf(Greeting::class, $serializer->decode($encoded)->getMessage());
    }

    #[Test]
    public function decodeRejectsMissingTypeHeader(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => 'x', 'headers' => []]);
    }

    #[Test]
    public function decodeRejectsUnknownType(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => 'x', 'headers' => ['type' => 'no-such-type']]);
    }

    #[Test]
    public function decodeRejectsMissingBody(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['headers' => ['type' => 'greeting']]);
    }

    #[Test]
    public function decodeWrapsDeserializationFailures(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => 'not-a-serialized-object', 'headers' => ['type' => 'greeting']]);
    }
}
```

- [ ] **Step 3: Run to verify FAIL, then implement**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Serialization
```

`packages/nexus-messenger/src/Serialization/NexusMessengerSerializer.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Serialization;

use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Messenger\Stamp\TargetActorPathStamp;
use Monadial\Nexus\Serialization\Exception\MessageDeserializationException;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function class_exists;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Messenger SerializerInterface backed by a Nexus MessageSerializer.
 *
 * Bodies are (de)serialized by the injected Nexus serializer; the message
 * type travels in the "type" header (registered #[MessageType] name when
 * available, FQCN otherwise). The bridge's own stamps round-trip as plain
 * string headers. Other stamps are NOT preserved in v1 — swap in any Symfony
 * SerializerInterface if you need full stamp fidelity or interop with
 * non-Nexus producers.
 *
 * @psalm-api
 */
final readonly class NexusMessengerSerializer implements SerializerInterface
{
    private const string HEADER_SOURCE_PATH = 'X-Nexus-Source-Path';
    private const string HEADER_TARGET_PATH = 'X-Nexus-Target-Path';
    private const string HEADER_TYPE = 'type';

    public function __construct(
        private MessageSerializer $messages,
        private TypeRegistry $types,
    ) {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? null;
        $headers = $encodedEnvelope['headers'] ?? [];

        if (!is_string($body) || !is_array($headers)) {
            throw new MessageDecodingFailedException('Encoded envelope must contain a string "body" and array "headers".');
        }

        $type = $headers[self::HEADER_TYPE] ?? null;

        if (!is_string($type) || $type === '') {
            throw new MessageDecodingFailedException('Encoded envelope is missing the "type" header.');
        }

        $class = $this->types->classForName($type) ?? $type;

        if (!class_exists($class)) {
            throw new MessageDecodingFailedException(sprintf('Message type "%s" does not resolve to a known class.', $type));
        }

        try {
            $message = $this->messages->deserialize($body, $class);
        } catch (MessageDeserializationException $e) {
            throw new MessageDecodingFailedException($e->getMessage(), 0, $e);
        }

        return new Envelope($message, $this->stampsFromHeaders($headers));
    }

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        return [
            'body' => $this->messages->serialize($message),
            'headers' => $this->headersFor($envelope, $message),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Envelope $envelope, object $message): array
    {
        $headers = [self::HEADER_TYPE => $this->types->nameForClass($message::class) ?? $message::class];
        $source = $envelope->last(SourceActorPathStamp::class);

        if ($source instanceof SourceActorPathStamp) {
            $headers[self::HEADER_SOURCE_PATH] = $source->path;
        }

        $target = $envelope->last(TargetActorPathStamp::class);

        if ($target instanceof TargetActorPathStamp) {
            $headers[self::HEADER_TARGET_PATH] = $target->path;
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $headers
     * @return list<StampInterface>
     */
    private function stampsFromHeaders(array $headers): array
    {
        $stamps = [];
        $source = $headers[self::HEADER_SOURCE_PATH] ?? null;

        if (is_string($source) && $source !== '') {
            $stamps[] = new SourceActorPathStamp($source);
        }

        $target = $headers[self::HEADER_TARGET_PATH] ?? null;

        if (is_string($target) && $target !== '') {
            $stamps[] = new TargetActorPathStamp($target);
        }

        return $stamps;
    }
}
```

Note: `PhpNativeSerializer::deserialize()` on garbage input may throw `MessageDeserializationException` or return `false`-ish failure differently — if the "wraps deserialization failures" test fails, check what `PhpNativeSerializer` actually throws on invalid payloads and catch that (all its failures should derive from `Monadial\Nexus\Serialization\Exception\SerializationException`; widening the catch to `SerializationException` is acceptable).

- [ ] **Step 4: Run to verify PASS, commit**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit
make cs-fix && make phpcs && make psalm
git add packages/nexus-messenger
git commit -m "feat(messenger): Nexus-backed Messenger serializer with bridge-stamp headers"
```

---

### Task 6: Consumer — `ReceiverActorConfig`, `UnroutablePolicy`, `Poll`, `MessagesProcessed`, `ReceiverActor` + Fiber integration tests

**Files:**
- Create: `packages/nexus-messenger/src/Consumer/UnroutablePolicy.php`
- Create: `packages/nexus-messenger/src/Consumer/ReceiverActorConfig.php`
- Create: `packages/nexus-messenger/src/Consumer/Poll.php`
- Create: `packages/nexus-messenger/src/Lifecycle/MessagesProcessed.php`
- Create: `packages/nexus-messenger/src/Consumer/ReceiverActor.php`
- Test: `packages/nexus-messenger/tests/Unit/Consumer/ReceiverActorConfigTest.php`
- Test: `tests/Integration/Messenger/ReceiverActorTest.php`
- Create: `tests/Integration/Messenger/Support/TogglableBackpressureRef.php`
- Create: `tests/Integration/Messenger/Messages/Ping.php`
- Modify: `.github/workflows/ci.yml` — in the `integration-fiber` job, add a run line for `--testsuite=integration-messenger` mirroring the existing `integration-serialization` line.

**Interfaces:**
- Consumes: `MessageRouter` (Task 4), `BackpressureCapable` (Task 2), `EnqueueResult`, Symfony `ReceiverInterface` (`get(): iterable`, `ack(Envelope): void`, `reject(Envelope): void`), `Behavior`/`ActorContext` (`self()`, `scheduleOnce(Duration, object)`), Symfony `InMemoryTransport` (`Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport`; `getAcknowledged()`, `getRejected()`) for tests.
- Produces:
  - `enum UnroutablePolicy { case DeadLetters; case Reject; }`
  - `ReceiverActorConfig` — `final readonly`, public `Duration $pollInterval` / `UnroutablePolicy $unroutablePolicy`; `ReceiverActorConfig::default()` (100 ms, Reject), `withPollInterval()`, `withUnroutablePolicy()`.
  - `Poll` — internal `final readonly class Poll {}` message.
  - `MessagesProcessed` — `final readonly`, `public int $count` (consumed by Task 7 watchdog).
  - `ReceiverActor::create(ReceiverInterface $receiver, MessageRouter $router, ?ReceiverActorConfig $config = null, ?ActorRef $deadLetters = null, ?ActorRef $processedListener = null): Behavior`.

- [ ] **Step 1: Write the failing config unit test**

`packages/nexus-messenger/tests/Unit/Consumer/ReceiverActorConfigTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Consumer;

use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReceiverActorConfig::class)]
final class ReceiverActorConfigTest extends TestCase
{
    #[Test]
    public function defaultsTo100msPollAndReject(): void
    {
        $config = ReceiverActorConfig::default();

        self::assertTrue($config->pollInterval->equals(Duration::millis(100)));
        self::assertSame(UnroutablePolicy::Reject, $config->unroutablePolicy);
    }

    #[Test]
    public function withersReturnModifiedCopies(): void
    {
        $config = ReceiverActorConfig::default()
            ->withPollInterval(Duration::millis(20))
            ->withUnroutablePolicy(UnroutablePolicy::DeadLetters);

        self::assertTrue($config->pollInterval->equals(Duration::millis(20)));
        self::assertSame(UnroutablePolicy::DeadLetters, $config->unroutablePolicy);
        self::assertSame(UnroutablePolicy::Reject, ReceiverActorConfig::default()->unroutablePolicy);
    }
}
```

- [ ] **Step 2: Run to verify FAIL, then implement the value objects**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Consumer
```

`packages/nexus-messenger/src/Consumer/UnroutablePolicy.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

/**
 * What the ReceiverActor does with an inbound message no router resolves.
 *
 * @psalm-api
 */
enum UnroutablePolicy
{
    case DeadLetters;
    case Reject;
}
```

`packages/nexus-messenger/src/Consumer/ReceiverActorConfig.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

use Monadial\Nexus\Runtime\Duration;

/**
 * Tuning knobs for a ReceiverActor poll loop.
 *
 * @psalm-api
 */
final readonly class ReceiverActorConfig
{
    private function __construct(
        public Duration $pollInterval,
        public UnroutablePolicy $unroutablePolicy,
    ) {
    }

    public static function default(): self
    {
        return new self(Duration::millis(100), UnroutablePolicy::Reject);
    }

    public function withPollInterval(Duration $pollInterval): self
    {
        return new self($pollInterval, $this->unroutablePolicy);
    }

    public function withUnroutablePolicy(UnroutablePolicy $unroutablePolicy): self
    {
        return new self($this->pollInterval, $unroutablePolicy);
    }
}
```

`packages/nexus-messenger/src/Consumer/Poll.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

/**
 * Internal self-tick message driving the ReceiverActor poll loop.
 *
 * @psalm-api
 */
final readonly class Poll
{
}
```

`packages/nexus-messenger/src/Lifecycle/MessagesProcessed.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

/**
 * Progress report from a ReceiverActor: N messages were routed and acked in
 * one poll tick. Consumed by the LifecycleWatchdog message-count threshold.
 *
 * @psalm-api
 */
final readonly class MessagesProcessed
{
    public function __construct(public int $count)
    {
    }
}
```

Run the config test again — expected: PASS.

- [ ] **Step 3: Implement `ReceiverActor`**

`packages/nexus-messenger/src/Consumer/ReceiverActor.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Supervised poll → route → ack loop over one Messenger receiver.
 *
 * Each Poll tick drains the envelopes the receiver has available now:
 * routed messages are offered to the target's mailbox and acked only when
 * accepted; a Backpressured/Dropped enqueue stops the tick without acking,
 * so the broker redelivers (at-least-once). Unroutable messages are rejected
 * or forwarded to dead letters per ReceiverActorConfig. When the receiver is
 * idle the next poll is scheduled after pollInterval; a busy tick re-polls
 * immediately.
 *
 * Spawn one per receiver, under supervision for broker-blip restarts:
 * ```php
 * $system->spawn(
 *     Props::fromBehavior(ReceiverActor::create($transport, $router)),
 *     'orders-receiver',
 * );
 * ```
 *
 * @psalm-api
 */
final readonly class ReceiverActor
{
    private function __construct()
    {
    }

    /**
     * @param ActorRef<object>|null $deadLetters required when the config policy is UnroutablePolicy::DeadLetters
     * @param ActorRef<object>|null $processedListener receives MessagesProcessed reports (e.g. the LifecycleWatchdog)
     * @return Behavior<object>
     */
    public static function create(
        ReceiverInterface $receiver,
        MessageRouter $router,
        ?ReceiverActorConfig $config = null,
        ?ActorRef $deadLetters = null,
        ?ActorRef $processedListener = null,
    ): Behavior {
        $config ??= ReceiverActorConfig::default();

        return Behavior::setup(
            static function (ActorContext $ctx) use ($receiver, $router, $config, $deadLetters, $processedListener): Behavior {
                $ctx->self()->tell(new Poll());

                return Behavior::receive(
                    static function (ActorContext $ctx, object $message) use ($receiver, $router, $config, $deadLetters, $processedListener): Behavior {
                        if (!$message instanceof Poll) {
                            return Behavior::unhandled();
                        }

                        [$processed, $backpressured] = self::drainOnce($receiver, $router, $config, $deadLetters);

                        if ($processed > 0 && $processedListener !== null) {
                            $processedListener->tell(new MessagesProcessed($processed));
                        }

                        if ($processed > 0 && !$backpressured) {
                            $ctx->self()->tell(new Poll());
                        } else {
                            $ctx->scheduleOnce($config->pollInterval, new Poll());
                        }

                        return Behavior::same();
                    },
                );
            },
        );
    }

    /**
     * @param ActorRef<object>|null $deadLetters
     * @return array{0: int, 1: bool} messages acked this tick, whether the tick stopped on backpressure
     */
    private static function drainOnce(
        ReceiverInterface $receiver,
        MessageRouter $router,
        ReceiverActorConfig $config,
        ?ActorRef $deadLetters,
    ): array {
        $processed = 0;

        foreach ($receiver->get() as $envelope) {
            if (!$envelope instanceof Envelope) {
                continue;
            }

            $inner = $envelope->getMessage();
            $target = $router->route($inner, $envelope);

            if ($target === null) {
                self::handleUnroutable($receiver, $config, $deadLetters, $envelope);

                continue;
            }

            if ($target instanceof BackpressureCapable) {
                if ($target->offer($inner) !== EnqueueResult::Accepted) {
                    return [$processed, true];
                }
            } else {
                $target->tell($inner);
            }

            $receiver->ack($envelope);
            $processed++;
        }

        return [$processed, false];
    }

    /**
     * @param ActorRef<object>|null $deadLetters
     */
    private static function handleUnroutable(
        ReceiverInterface $receiver,
        ReceiverActorConfig $config,
        ?ActorRef $deadLetters,
        Envelope $envelope,
    ): void {
        if ($config->unroutablePolicy === UnroutablePolicy::DeadLetters && $deadLetters !== null) {
            $deadLetters->tell($envelope->getMessage());
            $receiver->ack($envelope);

            return;
        }

        $receiver->reject($envelope);
    }
}
```

- [ ] **Step 4: Write the integration tests**

`tests/Integration/Messenger/Messages/Ping.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger\Messages;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('ping')]
final readonly class Ping
{
    public function __construct(public string $id)
    {
    }
}
```

`tests/Integration/Messenger/Support/TogglableBackpressureRef.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger\Support;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\BackpressureCapable;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use RuntimeException;

/**
 * @template-implements ActorRef<object>
 */
final class TogglableBackpressureRef implements ActorRef, BackpressureCapable
{
    public EnqueueResult $result = EnqueueResult::Backpressured;

    /** @var list<object> */
    public array $accepted = [];

    public function offer(object $message): EnqueueResult
    {
        if ($this->result === EnqueueResult::Accepted) {
            $this->accepted[] = $message;
        }

        return $this->result;
    }

    public function tell(object $message): void
    {
        $this->accepted[] = $message;
    }

    public function ask(object $message, Duration $timeout): Future
    {
        throw new RuntimeException('not used in this test');
    }

    public function path(): ActorPath
    {
        return ActorPath::root()->child('togglable');
    }

    public function isAlive(): bool
    {
        return true;
    }
}
```

`tests/Integration/Messenger/ReceiverActorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActor;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Ping;
use Monadial\Nexus\Tests\Integration\Messenger\Support\TogglableBackpressureRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(ReceiverActor::class)]
final class ReceiverActorTest extends TestCase
{
    #[Test]
    public function consumesRoutesAndAcksQueuedMessages(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('receiver-happy', $runtime);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new Ping('a')));
        $transport->send(new Envelope(new Ping('b')));

        $received = [];
        $target = $system->spawn(Props::fromBehavior(Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof Ping) {
                    $received[] = $msg->id;
                }

                return Behavior::same();
            },
        )), 'target');

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([Ping::class => $target]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame(['a', 'b'], $received);
        self::assertCount(2, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }

    #[Test]
    public function rejectsUnroutableMessagesByDefault(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('receiver-unroutable', $runtime);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new Ping('lost')));

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertCount(1, $transport->getRejected());
        self::assertCount(0, $transport->getAcknowledged());
    }

    #[Test]
    public function forwardsUnroutableMessagesToDeadLettersWhenConfigured(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('receiver-deadletters', $runtime);
        $transport = new InMemoryTransport();
        $message = new Ping('dead');
        $transport->send(new Envelope($message));

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([]),
            ReceiverActorConfig::default()
                ->withPollInterval(Duration::millis(20))
                ->withUnroutablePolicy(UnroutablePolicy::DeadLetters),
            $system->deadLetters(),
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertContains($message, $system->deadLetters()->captured());
        self::assertCount(1, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }

    #[Test]
    public function backpressuredEnqueueIsNotAckedAndIsRedeliveredLater(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('receiver-backpressure', $runtime);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new Ping('bp')));
        $fake = new TogglableBackpressureRef();

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([Ping::class => $fake]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(150), static function () use ($fake): void {
            $fake->result = EnqueueResult::Accepted;
        });
        $runtime->scheduleOnce(Duration::millis(400), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertCount(1, $fake->accepted);
        self::assertInstanceOf(Ping::class, $fake->accepted[0]);
        self::assertCount(1, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }
}
```

- [ ] **Step 5: Run the integration suite**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=integration-messenger
```

Expected: PASS (4 tests). If `InMemoryTransport` lives elsewhere in the installed version, fix the import (`vendor/symfony/messenger/Transport/InMemory/`).

- [ ] **Step 6: Add the suite to CI**

In `.github/workflows/ci.yml`, find the `integration-fiber` job and add a step/line running `--testsuite=integration-messenger` exactly parallel to the existing `integration-serialization` invocation.

- [ ] **Step 7: Full checks and commit**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
git add packages/nexus-messenger tests/Integration/Messenger .github/workflows/ci.yml
git commit -m "feat(messenger): supervised ReceiverActor poll loop with ack-on-accept and backpressure"
```

---

### Task 7: Lifecycle — `LifecycleThresholds`, `Tick`, `LifecycleWatchdog` + integration tests

**Files:**
- Create: `packages/nexus-messenger/src/Lifecycle/LifecycleThresholds.php`
- Create: `packages/nexus-messenger/src/Lifecycle/Tick.php`
- Create: `packages/nexus-messenger/src/Lifecycle/LifecycleWatchdog.php`
- Test: `packages/nexus-messenger/tests/Unit/Lifecycle/LifecycleThresholdsTest.php`
- Test: `tests/Integration/Messenger/LifecycleWatchdogTest.php`

**Interfaces:**
- Consumes: `MessagesProcessed` (Task 6), `ActorSystem::clock(): ClockInterface`, `ActorSystem::shutdown(Duration)`, `ActorContext::scheduleRepeatedly(Duration, Duration, object)`, `ActorContext::spawnTask(Closure)`, `Behavior::withState`/`BehaviorWithState`.
- Produces:
  - `LifecycleThresholds` — `final readonly`; publics `?int $memoryLimitBytes`, `?int $messageLimit`, `?Duration $timeLimit`; `LifecycleThresholds::none()`, `withMemoryLimit(int)`, `withMessageLimit(int)`, `withTimeLimit(Duration)`; **`breachReason(int $memoryBytes, Duration $uptime, int $processedCount): ?string`** (null = no breach).
  - `Tick` — internal `final readonly class Tick {}`.
  - `LifecycleWatchdog::create(ActorSystem $system, LifecycleThresholds $thresholds, ?Duration $checkInterval = null, ?Duration $shutdownTimeout = null, ?Closure $memoryProbe = null): Behavior` (defaults: 5 s interval, 10 s shutdown timeout, `memory_get_usage(true)` probe). Task 8 wraps this in `MessengerBridge::watchdogProps()`.

- [ ] **Step 1: Write the failing thresholds unit test**

`packages/nexus-messenger/tests/Unit/Lifecycle/LifecycleThresholdsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Lifecycle;

use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LifecycleThresholds::class)]
final class LifecycleThresholdsTest extends TestCase
{
    #[Test]
    public function noneNeverBreaches(): void
    {
        $thresholds = LifecycleThresholds::none();

        self::assertNull($thresholds->breachReason(PHP_INT_MAX, Duration::seconds(86400), PHP_INT_MAX));
    }

    #[Test]
    public function memoryLimitBreachesAtOrAboveBudget(): void
    {
        $thresholds = LifecycleThresholds::none()->withMemoryLimit(1024);

        self::assertNull($thresholds->breachReason(1023, Duration::zero(), 0));
        self::assertNotNull($thresholds->breachReason(1024, Duration::zero(), 0));
        self::assertNotNull($thresholds->breachReason(4096, Duration::zero(), 0));
    }

    #[Test]
    public function messageLimitBreachesAtOrAboveCount(): void
    {
        $thresholds = LifecycleThresholds::none()->withMessageLimit(100);

        self::assertNull($thresholds->breachReason(0, Duration::zero(), 99));
        self::assertNotNull($thresholds->breachReason(0, Duration::zero(), 100));
    }

    #[Test]
    public function timeLimitBreachesAtOrAboveUptime(): void
    {
        $thresholds = LifecycleThresholds::none()->withTimeLimit(Duration::seconds(60));

        self::assertNull($thresholds->breachReason(0, Duration::seconds(59), 0));
        self::assertNotNull($thresholds->breachReason(0, Duration::seconds(60), 0));
    }

    #[Test]
    public function breachReasonNamesTheThreshold(): void
    {
        $memory = LifecycleThresholds::none()->withMemoryLimit(1)->breachReason(2, Duration::zero(), 0);
        $count = LifecycleThresholds::none()->withMessageLimit(1)->breachReason(0, Duration::zero(), 1);
        $time = LifecycleThresholds::none()->withTimeLimit(Duration::seconds(1))->breachReason(0, Duration::seconds(2), 0);

        self::assertStringContainsString('memory', (string) $memory);
        self::assertStringContainsString('message', (string) $count);
        self::assertStringContainsString('uptime', (string) $time);
    }
}
```

- [ ] **Step 2: Run to verify FAIL, then implement**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-messenger/tests/Unit/Lifecycle
```

`packages/nexus-messenger/src/Lifecycle/LifecycleThresholds.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

use Monadial\Nexus\Runtime\Duration;

use function sprintf;

/**
 * Worker-recycling limits evaluated by the LifecycleWatchdog. A null limit is
 * disabled. All comparisons are inclusive: reaching the limit breaches it.
 *
 * @psalm-api
 */
final readonly class LifecycleThresholds
{
    private function __construct(
        public ?int $memoryLimitBytes,
        public ?int $messageLimit,
        public ?Duration $timeLimit,
    ) {
    }

    public static function none(): self
    {
        return new self(null, null, null);
    }

    public function withMemoryLimit(int $bytes): self
    {
        return new self($bytes, $this->messageLimit, $this->timeLimit);
    }

    public function withMessageLimit(int $count): self
    {
        return new self($this->memoryLimitBytes, $count, $this->timeLimit);
    }

    public function withTimeLimit(Duration $limit): self
    {
        return new self($this->memoryLimitBytes, $this->messageLimit, $limit);
    }

    /**
     * Returns a human-readable breach description, or null when no threshold
     * is breached.
     */
    public function breachReason(int $memoryBytes, Duration $uptime, int $processedCount): ?string
    {
        if ($this->memoryLimitBytes !== null && $memoryBytes >= $this->memoryLimitBytes) {
            return sprintf('memory usage %d bytes reached the %d byte limit', $memoryBytes, $this->memoryLimitBytes);
        }

        if ($this->messageLimit !== null && $processedCount >= $this->messageLimit) {
            return sprintf('processed %d messages, reaching the limit of %d', $processedCount, $this->messageLimit);
        }

        if ($this->timeLimit !== null && !$uptime->isLessThan($this->timeLimit)) {
            return sprintf('uptime %ds reached the %ds limit', $uptime->toSeconds(), $this->timeLimit->toSeconds());
        }

        return null;
    }
}
```

`packages/nexus-messenger/src/Lifecycle/Tick.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

/**
 * Internal self-tick message driving LifecycleWatchdog threshold checks.
 *
 * @psalm-api
 */
final readonly class Tick
{
}
```

Run the unit test again — expected: PASS. (If `Duration::toSeconds()` returns non-int, cast for sprintf and suppress per the Psalm rule in CLAUDE.md if needed.)

- [ ] **Step 3: Implement `LifecycleWatchdog`**

`packages/nexus-messenger/src/Lifecycle/LifecycleWatchdog.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Lifecycle;

use Closure;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Runtime\Duration;

use function is_int;
use function memory_get_usage;

/**
 * Worker-recycling actor: self-ticks on a fixed interval and triggers a
 * graceful ActorSystem::shutdown() when any LifecycleThresholds limit is
 * reached (memory budget, uptime, or cumulative MessagesProcessed count as
 * reported by ReceiverActor instances).
 *
 * Long-running PHP processes leak; the standard defense is "exit gracefully
 * after N messages / X memory / T uptime and let the process manager
 * (systemd, supervisor, k8s) restart". This replaces messenger:consume's
 * --limit/--memory-limit/--time-limit flags with a plain supervised actor —
 * no symfony/console involved. Uptime is measured with second precision via
 * the system clock.
 *
 * Example:
 * ```php
 * $system->spawn(Props::fromBehavior(LifecycleWatchdog::create(
 *     $system,
 *     LifecycleThresholds::none()->withMessageLimit(10_000),
 * )), 'watchdog');
 * ```
 *
 * @psalm-api
 */
final readonly class LifecycleWatchdog
{
    private function __construct()
    {
    }

    /**
     * @param Closure(): int|null $memoryProbe returns current usage in bytes; defaults to memory_get_usage(true)
     * @return Behavior<object>
     */
    public static function create(
        ActorSystem $system,
        LifecycleThresholds $thresholds,
        ?Duration $checkInterval = null,
        ?Duration $shutdownTimeout = null,
        ?Closure $memoryProbe = null,
    ): Behavior {
        $interval = $checkInterval ?? Duration::seconds(5);
        $timeout = $shutdownTimeout ?? Duration::seconds(10);
        $probe = $memoryProbe ?? static fn(): int => memory_get_usage(true);

        return Behavior::setup(
            static function (ActorContext $ctx) use ($system, $thresholds, $interval, $timeout, $probe): Behavior {
                $startedAt = $system->clock()->now()->getTimestamp();
                $ctx->scheduleRepeatedly($interval, $interval, new Tick());

                return Behavior::withState(
                    0,
                    static function (ActorContext $ctx, object $message, mixed $processed) use ($system, $thresholds, $timeout, $probe, $startedAt): BehaviorWithState {
                        $count = is_int($processed)
                            ? $processed
                            : 0;

                        if ($message instanceof MessagesProcessed) {
                            return BehaviorWithState::next($count + $message->count);
                        }

                        if (!$message instanceof Tick) {
                            return BehaviorWithState::same();
                        }

                        $uptime = Duration::seconds($system->clock()->now()->getTimestamp() - $startedAt);
                        $reason = $thresholds->breachReason($probe(), $uptime, $count);

                        if ($reason !== null) {
                            $ctx->log()->info('LifecycleWatchdog triggering graceful shutdown', ['reason' => $reason]);
                            $ctx->spawnTask(static function () use ($system, $timeout): void {
                                $system->shutdown($timeout);
                            });
                        }

                        return BehaviorWithState::same();
                    },
                );
            },
        );
    }
}
```

Note: the shutdown runs in a detached task (`spawnTask`) rather than inline, so the watchdog's own message-processing loop is not re-entered while `shutdown()` cooperatively drains actors. If `spawnTask` turns out to require different closure semantics, fall back to `$system->runtime()->spawn(...)`.

- [ ] **Step 4: Write the watchdog integration tests**

`tests/Integration/Messenger/LifecycleWatchdogTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleWatchdog;
use Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LifecycleWatchdog::class)]
final class LifecycleWatchdogTest extends TestCase
{
    #[Test]
    public function shutsTheSystemDownWhenTheMessageLimitIsReached(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('watchdog-messages', $runtime);
        $safetyTriggered = false;

        $watchdog = $system->spawn(Props::fromBehavior(LifecycleWatchdog::create(
            $system,
            LifecycleThresholds::none()->withMessageLimit(3),
            Duration::millis(30),
            Duration::seconds(1),
        )), 'watchdog');

        $watchdog->tell(new MessagesProcessed(2));
        $watchdog->tell(new MessagesProcessed(1));

        $runtime->scheduleOnce(Duration::seconds(3), static function () use ($system, &$safetyTriggered): void {
            $safetyTriggered = true;
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertFalse($safetyTriggered, 'watchdog should have shut the system down before the safety net');
    }

    #[Test]
    public function shutsTheSystemDownWhenTheMemoryBudgetIsExceeded(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('watchdog-memory', $runtime);
        $safetyTriggered = false;

        $system->spawn(Props::fromBehavior(LifecycleWatchdog::create(
            $system,
            LifecycleThresholds::none()->withMemoryLimit(1024),
            Duration::millis(30),
            Duration::seconds(1),
            static fn(): int => 2048,
        )), 'watchdog');

        $runtime->scheduleOnce(Duration::seconds(3), static function () use ($system, &$safetyTriggered): void {
            $safetyTriggered = true;
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertFalse($safetyTriggered, 'watchdog should have shut the system down before the safety net');
    }
}
```

- [ ] **Step 5: Run, verify PASS, commit**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=integration-messenger
docker compose exec php vendor/bin/phpunit --testsuite=unit
make cs-fix && make phpcs && make psalm
git add packages/nexus-messenger tests/Integration/Messenger
git commit -m "feat(messenger): LifecycleWatchdog worker recycling via graceful shutdown"
```

---

### Task 8: `MessengerBridge` wiring helpers + end-to-end integration test

**Files:**
- Create: `packages/nexus-messenger/src/MessengerBridge.php`
- Test: `tests/Integration/Messenger/ProducerConsumerLoopTest.php`
- Test: `tests/Integration/Messenger/CompetingReceiversTest.php`
- Create: `tests/Integration/Messenger/Messages/OrderPlaced.php`

**Interfaces:**
- Consumes: everything from Tasks 3–7.
- Produces: `MessengerBridge` static facade — `producer()`, `gateway()`, `receiverProps()`, `watchdogProps()`, and **`spawnReceivers()` (in-process horizontal scaling: N competing consumers over one transport)**. This is the documented entry point in Tasks 10–12.

**Horizontal scaling model (documented in Tasks 11–12):** "run 10 messenger workers" is served at three layers — (a) `spawnReceivers($system, 10, ...)` for N competing `ReceiverActor`s in one process (most useful on Swoole where transport clients are coroutine-aware), (b) N OS processes of the same worker, load-balanced by the broker and recycled by `LifecycleWatchdog` + process manager (zero code, the operationally standard model), and (c) handler-side parallelism by routing to a pool of target actors (existing Nexus routing patterns). Because every `ReceiverActor` acks only what it enqueues, competing consumers stay at-least-once safe.

- [ ] **Step 1: Implement `MessengerBridge`**

`packages/nexus-messenger/src/MessengerBridge.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger;

use Closure;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActor;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleWatchdog;
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Messenger\Producer\MessengerGateway;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Runtime\Duration;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Bootstrap conveniences for wiring the Messenger bridge in a few lines —
 * plain factories, no container magic.
 *
 * Example (Nexus-owned queue worker):
 * ```php
 * $system->spawn(MessengerBridge::receiverProps($transport, $router), 'in');
 * $system->spawn(MessengerBridge::watchdogProps(
 *     $system,
 *     LifecycleThresholds::none()->withMessageLimit(10_000),
 * ), 'watchdog');
 * $orders = MessengerBridge::producer($transport, 'orders-out');
 * ```
 *
 * @psalm-api
 */
final readonly class MessengerBridge
{
    private function __construct()
    {
    }

    public static function gateway(SenderInterface $sender): MessengerGateway
    {
        return new MessengerGateway($sender);
    }

    /**
     * @template T of object
     * @return MessengerActorRef<T>
     */
    public static function producer(SenderInterface $sender, string $name, ?ActorPath $sourcePath = null): MessengerActorRef
    {
        return new MessengerActorRef($sender, $name, $sourcePath);
    }

    /**
     * @param ActorRef<object>|null $deadLetters
     * @param ActorRef<object>|null $processedListener
     */
    public static function receiverProps(
        ReceiverInterface $receiver,
        MessageRouter $router,
        ?ReceiverActorConfig $config = null,
        ?ActorRef $deadLetters = null,
        ?ActorRef $processedListener = null,
    ): Props {
        return Props::fromBehavior(ReceiverActor::create($receiver, $router, $config, $deadLetters, $processedListener));
    }

    /**
     * Spawn N competing ReceiverActors over the same receiver — in-process
     * horizontal scaling. Actors are named "<namePrefix>-0" … "<namePrefix>-{N-1}"
     * and each polls, routes, and acks independently; because acks happen only
     * on accepted enqueue, competing consumers preserve at-least-once
     * semantics. For scaling across processes or machines, run multiple
     * worker processes instead — the broker load-balances between them.
     *
     * @param ActorRef<object>|null $deadLetters
     * @param ActorRef<object>|null $processedListener
     * @return list<ActorRef<object>>
     */
    public static function spawnReceivers(
        ActorSystem $system,
        int $count,
        string $namePrefix,
        ReceiverInterface $receiver,
        MessageRouter $router,
        ?ReceiverActorConfig $config = null,
        ?ActorRef $deadLetters = null,
        ?ActorRef $processedListener = null,
    ): array {
        if ($count < 1) {
            throw new InvalidArgumentException('Receiver count must be at least 1.');
        }

        $refs = [];

        for ($i = 0; $i < $count; $i++) {
            $refs[] = $system->spawn(
                self::receiverProps($receiver, $router, $config, $deadLetters, $processedListener),
                $namePrefix . '-' . $i,
            );
        }

        return $refs;
    }

    /**
     * @param Closure(): int|null $memoryProbe
     */
    public static function watchdogProps(
        ActorSystem $system,
        LifecycleThresholds $thresholds,
        ?Duration $checkInterval = null,
        ?Duration $shutdownTimeout = null,
        ?Closure $memoryProbe = null,
    ): Props {
        return Props::fromBehavior(LifecycleWatchdog::create($system, $thresholds, $checkInterval, $shutdownTimeout, $memoryProbe));
    }
}
```

(Add `use InvalidArgumentException;` to the import list.)

(If Psalm level 1 requires template params on the `Props` return types, annotate `@return Props<object>`.)

- [ ] **Step 2: Write the end-to-end test**

`tests/Integration/Messenger/Messages/OrderPlaced.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger\Messages;

use Monadial\Nexus\Serialization\MessageType;

#[MessageType('order-placed')]
final readonly class OrderPlaced
{
    public function __construct(public string $orderId)
    {
    }
}
```

`tests/Integration/Messenger/ProducerConsumerLoopTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\OrderPlaced;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(MessengerBridge::class)]
final class ProducerConsumerLoopTest extends TestCase
{
    #[Test]
    public function fullLoopFromProducerThroughTransportToTargetActor(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('messenger-e2e', $runtime);
        $transport = new InMemoryTransport();

        $received = [];
        $target = $system->spawn(Props::fromBehavior(Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof OrderPlaced) {
                    $received[] = $msg->orderId;
                }

                return Behavior::same();
            },
        )), 'orders');

        $watchdog = $system->spawn(MessengerBridge::watchdogProps(
            $system,
            LifecycleThresholds::none()->withMessageLimit(3),
            Duration::millis(30),
            Duration::seconds(1),
        ), 'watchdog');

        $system->spawn(MessengerBridge::receiverProps(
            $transport,
            new MapMessageRouter([OrderPlaced::class => $target]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
            null,
            $watchdog,
        ), 'receiver');

        $producer = MessengerBridge::producer($transport, 'orders-out');
        $producer->tell(new OrderPlaced('A'));
        $producer->tell(new OrderPlaced('B'));
        $producer->tell(new OrderPlaced('C'));

        $safetyTriggered = false;
        $runtime->scheduleOnce(Duration::seconds(3), static function () use ($system, &$safetyTriggered): void {
            $safetyTriggered = true;
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame(['A', 'B', 'C'], $received);
        self::assertCount(3, $transport->getAcknowledged());
        self::assertFalse($safetyTriggered, 'watchdog message limit should have ended the run');
    }
}
```

- [ ] **Step 3: Write the competing-receivers scaling test**

`tests/Integration/Messenger/CompetingReceiversTest.php`:

```php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\OrderPlaced;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(MessengerBridge::class)]
final class CompetingReceiversTest extends TestCase
{
    #[Test]
    public function multipleReceiversDrainOneTransportWithoutLossOrRejects(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('competing-receivers', $runtime);
        $transport = new InMemoryTransport();

        foreach (['a', 'b', 'c', 'd'] as $id) {
            $transport->send(new Envelope(new OrderPlaced($id)));
        }

        $received = [];
        $target = $system->spawn(Props::fromBehavior(Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof OrderPlaced) {
                    $received[] = $msg->orderId;
                }

                return Behavior::same();
            },
        )), 'orders');

        $refs = MessengerBridge::spawnReceivers(
            $system,
            3,
            'receiver',
            $transport,
            new MapMessageRouter([OrderPlaced::class => $target]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
        );

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        \sort($received);

        self::assertCount(3, $refs);
        self::assertSame(['a', 'b', 'c', 'd'], $received);
        self::assertCount(4, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }
}
```

Note: on the single-threaded Fiber runtime the first receiver's tick usually drains the whole in-memory queue; the test asserts no loss, no rejects, and no duplicate processing across competing pollers. (With real brokers each competing consumer gets a disjoint slice; at-least-once still permits duplicates on redelivery.)

- [ ] **Step 4: Run, verify PASS, commit**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=integration-messenger
make cs-fix && make phpcs && make psalm
git add packages/nexus-messenger tests/Integration/Messenger
git commit -m "feat(messenger): MessengerBridge wiring helpers with full-loop and multi-receiver coverage"
```

---

### Task 9: Psalm plugin — govern `MessengerActorRef::tell()` messages

**Files:**
- Modify: `packages/nexus-psalm/src/Hook/NonSerializableRemoteMessageRule.php`
- Test: mirror the existing rule test in `packages/nexus-psalm/tests/` (find the test covering `NonSerializableRemoteMessageRule` and add MessengerActorRef cases the same way).

**Interfaces:**
- Consumes: `Monadial\Nexus\Messenger\Producer\MessengerActorRef` (Task 3).
- Produces: the Psalm rule now flags messages sent via `MessengerActorRef::tell()` that lack `#[MessageType]`, exactly as it does for `WorkerActorRef::tell()`.

- [ ] **Step 1: Extend the rule**

In `NonSerializableRemoteMessageRule.php`:

1. Add to the `CHECKED_METHODS` const — **keep string keys alphabetically sorted** (messenger sorts between core and workerpool):

```php
'monadial\nexus\messenger\producer\messengeractorref::tell' => 0,
```

2. Extend `callerIsRemoteRef()` so it also matches `Monadial\Nexus\Messenger\Producer\MessengerActorRef`. Use a string literal FQCN (`'Monadial\\Nexus\\Messenger\\Producer\\MessengerActorRef'`) rather than `::class` **only if** importing the class would create an unwanted hard dependency for the standalone nexus-psalm package; if the existing code already imports `WorkerActorRef::class` from nexus-worker-pool, follow that precedent and import the class.

- [ ] **Step 2: Add rule tests**

Locate the existing test for this rule under `packages/nexus-psalm/tests/` and duplicate its WorkerActorRef fixture/case for MessengerActorRef: a message class **without** `#[MessageType]` told via `MessengerActorRef::tell()` must raise `NonSerializableRemoteMessage`; the same message **with** the attribute must not.

- [ ] **Step 3: Run the plugin tests and full checks, commit**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-psalm/tests
make cs-fix && make phpcs && make psalm
git add packages/nexus-psalm
git commit -m "feat(psalm): require #[MessageType] on MessengerActorRef::tell() messages"
```

---

### Task 10: Package README, CHANGELOG, CLAUDE.md, split.yml

**Files:**
- Create: `packages/nexus-messenger/README.md`
- Modify: `CHANGELOG.md` (Unreleased → Added)
- Modify: `CLAUDE.md` (package count, dependency graph, architecture subsection)
- Modify: `.github/workflows/split.yml` (matrix entry)

- [ ] **Step 1: Write the README** (matches the nexus-cluster template)

`packages/nexus-messenger/README.md`:

```markdown
# Nexus Messenger

Nexus messenger — two-way bridge between Nexus actors and Symfony Messenger transports: producer refs, supervised receiver actor, and worker recycling.

## Install

```bash
composer require nexus-actors/messenger
```

## Documentation

This is a read-only subtree split of [nexus-actors/nexus](https://github.com/nexus-actors/nexus).

Please refer to the main repository for documentation, issues, and pull requests.
```

- [ ] **Step 2: CHANGELOG**

Under `## [Unreleased]` → `### Added`, append:

```markdown
- Symfony Messenger bridge (`nexus-messenger`): MessengerActorRef/MessengerGateway producers, supervised ReceiverActor with backpressure-aware ack, pluggable MessageRouter, Nexus-backed Messenger serializer, and LifecycleWatchdog worker recycling
```

- [ ] **Step 3: CLAUDE.md**

1. Update the package count in Project Overview (33 → 34).
2. Add to the dependency graph under `nexus-serialization`'s subtree (it depends on Core, Runtime, Serialization):

```
├── nexus-messenger        → Core, Runtime, Serialization (+ symfony/messenger)
```

3. Add an architecture subsection after the Cluster section:

```markdown
### Messenger Bridge (nexus-messenger)

Two-way bridge to standalone `symfony/messenger` transports (no framework-bundle, no console).

- `MessengerActorRef<T>` — `ActorRef` backed by a Messenger `SenderInterface`; `tell()` publishes to the transport, `ask()` throws `UnsupportedOperationException` (v1). Messages need `#[MessageType]` (Psalm-enforced).
- `MessengerGateway` — explicit `publish(object, array $stamps = [])` egress service.
- `ReceiverActor` — supervised poll→route→ack loop per `ReceiverInterface`. Acks only on `EnqueueResult::Accepted` (via the core `BackpressureCapable` seam); backpressured/dropped enqueues are not acked so the broker redelivers (at-least-once). Unroutable messages: `reject()` by default, dead-letters opt-in (`ReceiverActorConfig`).
- `MessageRouter` — pluggable inbound routing: `MapMessageRouter` (class → ref, default), `StampMessageRouter` (TargetActorPathStamp → ref; cluster seam).
- `NexusMessengerSerializer` — Messenger `SerializerInterface` backed by a Nexus `MessageSerializer` + `TypeRegistry`; bridge stamps travel as headers.
- `LifecycleWatchdog` — worker recycling: triggers graceful `ActorSystem::shutdown()` on memory/uptime/message-count thresholds (`LifecycleThresholds`).
- `MessengerBridge` — static wiring facade: `producer()`, `gateway()`, `receiverProps()`, `watchdogProps()`.
```

4. Also add `BackpressureCapable` to the core section: one line under `ActorRef<T>`'s implementations noting `LocalActorRef` also implements `BackpressureCapable::offer(object): EnqueueResult` for delivery-feedback integrations.

- [ ] **Step 4: split.yml**

Add to the `strategy.matrix.package` list in `.github/workflows/split.yml` (aligned with existing formatting):

```yaml
- { local: 'nexus-messenger',            remote: 'messenger' }
```

**Prerequisite:** the `nexus-actors/messenger` GitHub repo must exist before this merges to main, or the split job will fail. Attempt `gh repo create nexus-actors/messenger --public -d "Read-only subtree split of nexus-actors/nexus"`; if permissions are missing, flag it to the user in the task report and leave the split.yml change in place.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-messenger/README.md CHANGELOG.md CLAUDE.md .github/workflows/split.yml
git commit -m "docs(messenger): README, changelog, repo map, and split wiring"
```

---

### Task 11: Website docs — package page, guide, sidebars, cross-links

**Files:**
- Create: `website/docs/packages/messenger.md`
- Create: `website/docs/guides/messenger-bridge.md`
- Modify: `website/sidebars.js` (register both pages)
- Modify: `website/docs/packages/cluster.md`, `website/docs/packages/serialization.md`, `website/docs/packages/worker-pool.md` (add `packages/messenger` to `related:` frontmatter)

- [ ] **Step 1: Package page**

`website/docs/packages/messenger.md` (follow cluster.md's structure exactly — frontmatter, one-line summary, What's in this package, Install, then sections):

````markdown
---
title: nexus-messenger
related:
  - packages/core
  - packages/serialization
  - packages/cluster
  - guides/messenger-bridge
---

# nexus-messenger

Two-way bridge between Nexus actors and standalone Symfony Messenger transports — publish from actors to any broker Messenger supports (AMQP, Redis, Doctrine, …) and consume broker messages into actor mailboxes, without hand-rolled transport code.

## What's in this package

- `MessengerActorRef<T>` — location-transparent `ActorRef` backed by a Messenger `SenderInterface`; `tell()` publishes to the transport
- `MessengerGateway` — explicit `publish()` egress service over the same sender
- `ReceiverActor` — supervised poll→route→ack loop, one per Messenger `ReceiverInterface`
- `ReceiverActorConfig` — poll interval and unroutable policy (reject vs dead letters)
- `MessageRouter` — pluggable inbound routing; `MapMessageRouter` (message class → ref) is the default, `StampMessageRouter` (target-path stamp → ref) is the cluster seam
- `NexusMessengerSerializer` — Messenger `SerializerInterface` backed by a Nexus `MessageSerializer`
- `LifecycleWatchdog` + `LifecycleThresholds` — worker recycling via graceful shutdown on memory/uptime/message-count limits
- `MessengerBridge` — static wiring facade (`producer()`, `gateway()`, `receiverProps()`, `watchdogProps()`)
- `SourceActorPathStamp` / `TargetActorPathStamp` — provenance and routing stamps
- `UnsupportedOperationException` — thrown by `MessengerActorRef::ask()` (broker request/reply is deferred beyond v1)

## Install

```bash
composer require nexus-actors/messenger
```

Depends on `nexus-actors/core`, `nexus-actors/runtime`, `nexus-actors/serialization`, and `symfony/messenger` only — never `framework-bundle` or `symfony/console`.

## Producer — actor → broker

```php
$orders = MessengerBridge::producer($transport, 'orders-out');
$orders->tell(new OrderPlaced('A-42')); // identical to a local tell()

MessengerBridge::gateway($transport)->publish(new OrderPlaced('A-42'));
```

Messages sent through `MessengerActorRef::tell()` must carry `#[MessageType]` (enforced by the nexus-psalm plugin). `ask()` throws `UnsupportedOperationException` in v1.

## Consumer — broker → actor

```php
$system->spawn(MessengerBridge::receiverProps(
    $transport,
    new MapMessageRouter([OrderPlaced::class => $ordersActor]),
), 'orders-receiver');
```

Delivery is at-least-once: the receiver acks only after the target mailbox accepts the message. A full (backpressured) mailbox pauses broker consumption — no ack, the broker redelivers. Unroutable messages are rejected by default; configure `UnroutablePolicy::DeadLetters` to forward them to `$system->deadLetters()` instead.

## Worker recycling

```php
$system->spawn(MessengerBridge::watchdogProps(
    $system,
    LifecycleThresholds::none()
        ->withMemoryLimit(256 * 1024 * 1024)
        ->withTimeLimit(Duration::seconds(3600))
        ->withMessageLimit(10_000),
), 'watchdog');
```

Replaces `messenger:consume --limit/--memory-limit/--time-limit`: when a threshold is reached the watchdog triggers a graceful `ActorSystem::shutdown()`, and your process manager (systemd, supervisor, k8s) restarts the worker.

## Scaling out

Three independent levers, combinable:

```php
// 1. N competing consumers in one process (best on Swoole coroutine transports)
MessengerBridge::spawnReceivers($system, 10, 'receiver', $transport, $router);
```

```bash
# 2. N worker processes — the broker load-balances between them; the
#    LifecycleWatchdog + your process manager handle recycling
systemctl start nexus-worker@{1..10}
```

3. Handler-side parallelism: route to a pool of target actors — see the [routing patterns guide](/docs/guides/routing-patterns).

Every receiver acks only what the target mailbox accepted, so all three levers preserve at-least-once semantics.

## Host models

- **Nexus owns the process** — boot an `ActorSystem` with receiver actor(s) plus the watchdog and call `run()`. Recommended for standalone queue workers.
- **Messenger owns the process** — an existing Symfony app keeps running `messenger:consume`; the bridge contributes routing and serialization, and actors run in-process.

## See also

- [Messenger bridge guide](/docs/guides/messenger-bridge)
- [Standalone integration](/docs/guides/standalone-integration)
````

- [ ] **Step 2: Guide page**

`website/docs/guides/messenger-bridge.md` — task-oriented walkthrough with these sections (write full prose + code, reusing the package page's snippets expanded with imports):

````markdown
---
title: Bridging Symfony Messenger
related:
  - packages/messenger
  - guides/standalone-integration
  - packages/serialization
---

# Bridging Symfony Messenger

<!-- Sections (each with a complete runnable snippet):
1. "When to reach for the bridge" — the three scenarios (queue worker, embed in Symfony, durable async boundary).
2. "Publishing from actors" — MessengerActorRef vs MessengerGateway, #[MessageType] requirement, ask() limitation.
3. "Consuming into actors" — building an InMemory/AMQP transport, MapMessageRouter, spawning ReceiverActor via MessengerBridge::receiverProps, supervision for broker blips, at-least-once semantics and the ack-on-accept rule.
4. "Handling unroutable messages" — Reject default vs DeadLetters policy.
5. "Swapping serialization" — NexusMessengerSerializer wiring (MessageSerializer + TypeRegistry) and when to use Symfony's native serializer instead; note the v1 bridge-stamps-only limitation.
6. "Recycling workers" — LifecycleWatchdog + LifecycleThresholds + process manager.
7. "Scaling to N workers" — the three levers: MessengerBridge::spawnReceivers() for in-process competing consumers, N OS processes (systemd/supervisor/k8s replicas) load-balanced by the broker, and target-actor pools (cross-link guides/routing-patterns and guides/fan-out); note all levers stay at-least-once because acks follow accepted enqueues.
8. "Choosing a host model" — Nexus-owned vs messenger:consume-owned decision table.
-->
````

Write the actual content for every section — the comment above is the required outline, not the deliverable.

- [ ] **Step 3: Register in `website/sidebars.js`**

Add `'packages/messenger'` to the Packages → Tooling group (next to `packages/serialization`), and `'guides/messenger-bridge'` to the Guides category (after `'guides/standalone-integration'`).

- [ ] **Step 4: Cross-links**

Add `- packages/messenger` to the `related:` frontmatter lists of `website/docs/packages/cluster.md`, `website/docs/packages/serialization.md`, and `website/docs/packages/worker-pool.md`.

- [ ] **Step 5: Build the site and commit**

```bash
cd website && npm run build && cd ..
```

Expected: build succeeds with no broken links (pre-existing lychee/axe failures in other tooling are known; the Docusaurus build itself must pass). Then:

```bash
git add website
git commit -m "docs(messenger): package page, bridge guide, sidebar and cross-links"
```

---

### Task 12: Website reference pages

**Files:**
- Create: `website/docs/reference/classes/messenger-actor-ref.md`
- Create: `website/docs/reference/classes/receiver-actor.md`
- Create: `website/docs/reference/classes/message-router.md`
- Create: `website/docs/reference/classes/lifecycle-watchdog.md`
- Create: `website/docs/reference/classes/nexus-messenger-serializer.md`
- Modify: `website/docs/reference/exceptions.md` (add `UnsupportedOperationException`)
- Modify: `website/docs/reference/config.md` (add `ReceiverActorConfig` + `LifecycleThresholds`)
- Modify: `website/docs/reference/attributes.md` (note `#[MessageType]` applies to `MessengerActorRef::tell()`)
- Modify: `website/sidebars.js` (add the five pages to the "Front-door classes" category)

- [ ] **Step 1: Create the five class pages**

Each follows the existing 25-page style (check `website/docs/reference/classes/worker-node.md` for the exact skeleton): frontmatter with `title`, `sidebar_position` (use the next unused integers after the current max — worker-node.md is 24, verify what the actual max is and continue from there), `related:` links; then purpose paragraph, constructor/factory signature block, method table, and a short example. Content sources — the docblocks written in Tasks 3–8:

- `messenger-actor-ref.md` — `tell()`/`ask()` (throws)/`path()` (synthetic `/messenger/<name>`)/`isAlive()` (always true); related: `packages/messenger`, `reference/classes/actor-ref`.
- `receiver-actor.md` — `ReceiverActor::create(...)` signature, poll/ack semantics, backpressure, `ReceiverActorConfig` inline mention; related: `packages/messenger`, `reference/classes/actor-system`.
- `message-router.md` — the interface + both implementations; related: `packages/messenger`, `packages/cluster`.
- `lifecycle-watchdog.md` — `create(...)` signature, thresholds, spawnTask shutdown, host-model note; related: `packages/messenger`, `reference/classes/actor-system`.
- `nexus-messenger-serializer.md` — encode/decode contract, header layout (`type`, `X-Nexus-Source-Path`, `X-Nexus-Target-Path`), stamp limitation; related: `packages/messenger`, `packages/serialization`.

- [ ] **Step 2: Update exceptions.md, config.md, attributes.md**

- `exceptions.md`: add `UnsupportedOperationException` (`Monadial\Nexus\Messenger\Exception`, extends `NexusException`) — thrown by `MessengerActorRef::ask()` in v1.
- `config.md`: document `ReceiverActorConfig` (pollInterval default 100 ms; unroutablePolicy default Reject, DeadLetters opt-in) and `LifecycleThresholds` (memory bytes / message count / uptime; all inclusive, null = disabled) plus watchdog defaults (5 s check interval, 10 s shutdown timeout).
- `attributes.md`: in the `#[MessageType]` entry, add `MessengerActorRef::tell()` to the list of governed call sites.

- [ ] **Step 3: Register the five pages in `website/sidebars.js`** under the "Front-door classes" category, after the existing last entry.

- [ ] **Step 4: Build and commit**

```bash
cd website && npm run build && cd ..
git add website
git commit -m "docs(messenger): reference pages for the bridge public API"
```

---

### Task 13: Final verification and PR

- [ ] **Step 1: Full verification battery**

```bash
make cs && make phpcs && make psalm
docker compose exec php php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac --config-file=deptrac.yaml
docker compose exec php vendor/bin/phpunit --testsuite=unit
docker compose exec php vendor/bin/phpunit --testsuite=integration-messenger
docker compose exec php vendor/bin/phpunit --testsuite=integration-fiber
docker compose exec php composer validate && docker compose exec php composer audit
cd website && npm run build && cd ..
```

Expected: everything green. Fix anything that isn't before proceeding.

- [ ] **Step 2: Push and open the PR**

Push via the gh/HTTPS remote if SSH agent is flaky (known quirk):

```bash
git push -u origin feat/nexus-messenger
gh pr create --title "feat: nexus-messenger — Symfony Messenger bridge" --body "$(cat <<'EOF'
## Summary

New `nexus-messenger` package: two-way bridge between Nexus actors and standalone `symfony/messenger` transports, per the approved design spec (`docs/superpowers/specs/2026-07-01-nexus-messenger-bridge-design.md`).

- **Producer**: `MessengerActorRef<T>` (location-transparent `tell()`; `ask()` throws `UnsupportedOperationException` in v1) and explicit `MessengerGateway::publish()`
- **Consumer**: supervised `ReceiverActor` poll→route→ack loop; acks only on accepted enqueue, so full mailboxes propagate backpressure to the broker (at-least-once)
- **Core seam**: new `BackpressureCapable` interface — `LocalActorRef::offer(): EnqueueResult` (tell() now delegates; behavior unchanged)
- **Routing**: pluggable `MessageRouter` — `MapMessageRouter` default, `StampMessageRouter` as the cluster seam
- **Serialization**: `NexusMessengerSerializer` backed by nexus-serialization (`TypeRegistry` + `MessageSerializer`); bridge stamps travel as headers
- **Worker recycling**: `LifecycleWatchdog` actor (memory/uptime/message-count → graceful shutdown), replacing `messenger:consume` limits without symfony/console
- **Horizontal scaling**: `MessengerBridge::spawnReceivers()` for N in-process competing consumers; N-process scale-out documented (broker load-balancing + watchdog recycling)
- **Psalm**: `MessengerActorRef::tell()` messages now require `#[MessageType]`
- **Docs**: package page, bridge guide, five reference class pages, README, CHANGELOG

## Test plan

- Unit: stamps, producer refs, routers, serializer round-trip, config, thresholds
- Integration (`integration-messenger`, wired into CI): receiver happy path / unroutable reject / dead-letters / backpressure-no-ack-redelivery, watchdog message+memory limits, full producer→transport→receiver→actor loop
EOF
)"
```

Note: the split.yml entry requires the `nexus-actors/messenger` repo to exist before merge (Task 10) — mention it in the PR if it could not be created.

---

## Execution notes

- Tasks 1→8 are strictly ordered. Task 9 depends on Task 3; Tasks 10–12 depend on the code being final; Task 13 is last.
- If any discovered API detail contradicts this plan (e.g. `ActorPath` rendering, `spawnTask` closure shape, `InMemoryTransport` namespace/getters), trust the codebase, adjust locally, and note the deviation in the task report — do not redesign.
- Every commit must pass GrumPHP (php-cs-fixer, phpcs, psalm level 1, full unit suite). No Claude attribution in commit messages.
