<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\Wallet\Domain\Command\WalletCommand;

/**
 * Immutable per-owner spawn cache held by `WalletDirectoryActor`.
 *
 * Keys are owner ids; values are `ActorRef`s pointing at live wallet actors.
 * `with()` returns a new registry — the actor swaps it via
 * `BehaviorWithState::next($registry->with(...))`, so the registry is
 * effectively a snapshot the supervisor can persist if it ever needs to.
 *
 * @psalm-api
 */
final readonly class WalletRegistry
{
    /**
     * @param array<string, ActorRef<WalletCommand>> $byOwner
     */
    public function __construct(public array $byOwner = []) {}

    /**
     * @return ActorRef<WalletCommand>|null
     */
    public function find(string $ownerId): ?ActorRef
    {
        return $this->byOwner[$ownerId] ?? null;
    }

    /**
     * @param ActorRef<WalletCommand> $ref
     */
    public function with(string $ownerId, ActorRef $ref): self
    {
        return new self([...$this->byOwner, $ownerId => $ref]);
    }
}
