---
sidebar_position: 2
title: Standalone Runtime Primitives
---

# Standalone Runtime Primitives

`nexus-runtime` provides `Future` and runtime abstractions independently from
`nexus-core`.

For a full bootstrapping flow (actor + standalone tracks), start with
[Bootstrap Runtime](./bootstrap.md).

## Install

```bash
composer require nexus-actors/runtime
```

## Future Example (No Actor System)

```php
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Runtime\Exception\FutureException;
use RuntimeException;

final class InlineFutureException extends RuntimeException implements FutureException {}

final class InlineSlot implements FutureSlot
{
    private ?object $value = null;

    public function resolve(object $value): void { $this->value = $value; }
    public function fail(FutureException $e): void { throw $e; }
    public function isResolved(): bool { return $this->value !== null; }
    public function await(): object { return $this->value ?? throw new RuntimeException('Not resolved'); }
}

$slot = new InlineSlot();
$slot->resolve((object) ['count' => 21]);

$future = new Future($slot);
$result = $future
    ->map(static fn(object $v): object => (object) ['count' => $v->count * 2])
    ->await();
```

## Runtime Contract

Concrete runtime packages (`runtime-fiber`, `runtime-swoole`, `runtime-step`)
implement `Monadial\Nexus\Runtime\Runtime\Runtime`.

Use this when you need to accept runtime implementations without depending on
core actor APIs at the call site.
