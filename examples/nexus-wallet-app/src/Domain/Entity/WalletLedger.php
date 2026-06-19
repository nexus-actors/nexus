<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\OneToMany;
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
final class WalletLedger
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

    /**
     * Append-only audit trail. Cascade-persist means the actor only
     * needs to call `appendEntry()`; flush picks them up automatically.
     *
     * @var Collection<int, LedgerEntry>
     */
    #[OneToMany(targetEntity: LedgerEntry::class, mappedBy: 'ledger', cascade: ['persist'])]
    public Collection $entries;

    public function __construct(string $ownerId)
    {
        $this->ownerId = $ownerId;
        $this->entries = new ArrayCollection();
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

    public function appendEntry(LedgerEntry $entry): void
    {
        $this->entries->add($entry);
    }
}
