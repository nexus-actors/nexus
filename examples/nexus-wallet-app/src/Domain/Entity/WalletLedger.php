<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;

/**
 * Per-owner running totals — a denormalised read model that lives in
 * SQLite next to the event-sourced wallet.
 *
 * Populated by `LedgerActor` (an EntityBehavior actor — see Actor/LedgerActor.php)
 * and read by `GET /wallet/ledger` via an injected `EntityManagerInterface`.
 *
 * The actor is the single writer for any given owner: `EntityRefFactory::of($ownerId)`
 * spawns at most one actor per owner per worker thread, so concurrent
 * record commands serialise inside the actor instead of contending on a
 * row lock.
 */
#[Entity]
#[Table(name: 'wallet_ledgers')]
class WalletLedger
{
    #[Id]
    #[Column]
    public string $ownerId;

    #[Column]
    public int $depositCount = 0;

    #[Column]
    public int $withdrawCount = 0;

    #[Column]
    public int $depositCents = 0;

    #[Column]
    public int $withdrawCents = 0;

    #[Column(nullable: true)]
    public ?DateTimeImmutable $lastActivityAt = null;

    public function __construct(string $ownerId)
    {
        $this->ownerId = $ownerId;
    }

    public function recordDeposit(int $cents, DateTimeImmutable $at): void
    {
        $this->depositCount++;
        $this->depositCents += $cents;
        $this->lastActivityAt = $at;
    }

    public function recordWithdraw(int $cents, DateTimeImmutable $at): void
    {
        $this->withdrawCount++;
        $this->withdrawCents += $cents;
        $this->lastActivityAt = $at;
    }
}
