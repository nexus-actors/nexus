<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\State;

use Monadial\Nexus\Example\Wallet\Domain\Money;

/**
 * The current snapshot of a wallet, reconstructed by folding events.
 *
 * The actor body in WalletActor pattern-matches event types and produces
 * a fresh WalletState — never mutates in place. That immutability is what
 * lets the event store snapshot the state safely and replay events for
 * recovery without race conditions.
 */
final readonly class WalletState
{
    public function __construct(public bool $opened, public Money $balance,) {}

    public static function empty(): self
    {
        return new self(opened: false, balance: Money::zero());
    }

    public function open(): self
    {
        return new self(opened: true, balance: $this->balance);
    }

    public function deposited(Money $amount): self
    {
        return new self(opened: true, balance: $this->balance->plus($amount));
    }

    public function withdrew(Money $amount): self
    {
        return new self(opened: true, balance: $this->balance->minus($amount));
    }
}
