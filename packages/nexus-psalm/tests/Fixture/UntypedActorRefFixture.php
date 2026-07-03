<?php

declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Tests\Fixture;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\LocalActorRef;

use function count;

final readonly class UarCommand {}

/** Bad: bare ActorRef param on a regular method. */
final class UarBareParamService
{
    public function setSink(ActorRef $sink): bool
    {
        return $sink->isAlive();
    }
}

/** Bad: explicit ActorRef<object> param. */
final class UarObjectParamService
{
    /** @param ActorRef<object> $orders */
    public function route(ActorRef $orders): bool
    {
        return $orders->isAlive();
    }
}

/** Bad: bypass attempt via concrete subtype. */
final class UarSubtypeBypassService
{
    public function connect(LocalActorRef $orders): bool
    {
        return $orders->isAlive();
    }
}

/** Bad: bare ActorRef closure param. */
final class UarClosureHost
{
    public function run(): bool
    {
        $probe = static fn(ActorRef $target): bool => $target->isAlive();

        return $probe !== null;
    }
}

/** Good: concrete message type. */
final class UarTypedParamService
{
    /** @param ActorRef<UarCommand> $orders */
    public function route(ActorRef $orders): bool
    {
        return $orders->isAlive();
    }
}

/**
 * Good: class-level template flows through.
 *
 * @template T of object
 */
final class UarTemplatedService
{
    /** @param ActorRef<T> $ref */
    public function forward(ActorRef $ref): bool
    {
        return $ref->isAlive();
    }
}

/** Good: nullable with typed generic. */
final class UarNullableTypedService
{
    /** @param ActorRef<UarCommand>|null $maybe */
    public function poke(?ActorRef $maybe): bool
    {
        return $maybe?->isAlive() ?? false;
    }
}

/** Good: suppression escape hatch. */
final class UarSuppressedService
{
    /** @psalm-suppress UntypedActorRefInjection */
    public function legacy(ActorRef $anything): bool
    {
        return $anything->isAlive();
    }
}

/** Good: non-ActorRef params are ignored. */
final class UarUnrelatedService
{
    public function greet(string $name): string
    {
        return 'hello ' . $name;
    }
}

/** Containers: bare and object-erased refs are flagged, typed pass. */
final class UarContainerService
{
    /** @param array<string, ActorRef> $bare */
    public function setBare(array $bare): int
    {
        return count($bare);
    }

    /** @param list<ActorRef<object>> $erased */
    public function setErased(array $erased): int
    {
        return count($erased);
    }

    /** @param array<class-string, ActorRef<UarCommand>> $typed */
    public function setTyped(array $typed): int
    {
        return count($typed);
    }
}
