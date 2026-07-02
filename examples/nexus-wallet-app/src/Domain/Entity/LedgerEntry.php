<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Domain\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Monadial\Nexus\Example\Wallet\Domain\LedgerKind;

/**
 * One row per recorded deposit or withdrawal — the full transaction
 * history for an owner. Read by `GET /wallet/ledger/entries` using an
 * injected `EntityManagerInterface` (repository-style query).
 *
 * Append-only by convention: the `LedgerActor` only ever `persist()`s
 * new entries; entries are never updated or deleted by the application.
 *
 * The many-to-one back to `WalletLedger` keeps the DB foreign key in
 * place but the actor doesn't traverse it — the running totals on the
 * ledger entity stay authoritative for fast reads.
 *
 * @psalm-api
 */
#[Entity]
#[Table(name: 'ledger_entries')]
final class LedgerEntry
{
    #[Id]
    #[GeneratedValue]
    #[Column]
    public ?int $id = null;

    #[ManyToOne(targetEntity: WalletLedger::class, inversedBy: 'entries')]
    #[JoinColumn(name: 'owner_id', referencedColumnName: 'ownerId')]
    public WalletLedger $ledger;

    #[Column(type: 'string', length: 16, enumType: LedgerKind::class)]
    public LedgerKind $kind;

    #[Column]
    public int $amountCents;

    #[Column]
    public DateTimeImmutable $occurredAt;

    public function __construct(WalletLedger $ledger, LedgerKind $kind, int $amountCents, DateTimeImmutable $occurredAt)
    {
        $this->ledger = $ledger;
        $this->kind = $kind;
        $this->amountCents = $amountCents;
        $this->occurredAt = $occurredAt;
    }
}
