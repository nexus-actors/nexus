<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Orm\Behavior\ActorSystemSpawner;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\CreateIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Example\Wallet\Domain\Command\LedgerCommand;
use Monadial\Nexus\Example\Wallet\Domain\Command\RecordLedger;
use Monadial\Nexus\Example\Wallet\Domain\Entity\LedgerEntry;
use Monadial\Nexus\Example\Wallet\Domain\Entity\WalletLedger;
use Monadial\Nexus\Example\Wallet\Domain\LedgerKind;
use Monadial\Nexus\Runtime\Duration;

/**
 * One LedgerActor per owner. Uses `EntityBehavior` — its state IS a
 * Doctrine entity. Updates run through the actor (single writer per
 * owner), the entity flushes on each `EntityEffect::persist()`.
 *
 * The factory returned by `LedgerActor::factory(...)` is the entry point:
 * - Built once per worker thread in `server.php`
 * - `->of($ownerId)` spawns at most one actor per owner per thread
 * - Send a `RecordLedger` command to update
 *
 * Why a separate actor instead of just SQL UPDATE in the handler? Two
 * reasons: (1) the actor handles serialisation per owner, so deposit
 * and withdraw can't interleave on the same row; (2) optimistic-lock
 * conflicts surface as `EntityConflictException` → supervised restart
 * → reload + retry, transparent to the caller.
 */
final class LedgerActor
{
    /**
     * @param array{
     *     dbname: string,
     *     driver: 'ibm_db2'|'mysqli'|'oci8'|'pdo_mysql'|'pdo_oci'|'pdo_pgsql'|'pdo_sqlite'|'pdo_sqlsrv'|'pgsql'|'sqlite3'|'sqlsrv',
     *     host: string,
     *     password: string,
     *     port: int,
     *     user: string,
     * } $connParams
     */
    public static function factory(ActorSystem $system, Configuration $ormConfig, array $connParams): EntityRefFactory
    {
        return EntityRefFactory::for(new ActorSystemSpawner($system), WalletLedger::class)
            ->using(new DefaultEntityManagerFactory($ormConfig))
            ->withConnectionSource(static fn(): Connection => DriverManager::getConnection($connParams))
            ->withReplayPolicy(new CreateIfMissing(
                static fn(string $ownerId): WalletLedger => new WalletLedger($ownerId),
            ))
            // Idle for 2 minutes? Passivate, release the dedicated EM +
            // Connection. Next command from EntityRefFactory::of() spawns
            // a fresh actor that reloads the WalletLedger from Postgres.
            ->withReceiveTimeout(Duration::seconds(120))
            ->handle(self::commandHandler())
            ->build();
    }

    /**
     * @return Closure(ActorContext<LedgerCommand>, LedgerCommand, WalletLedger): EntityEffect<WalletLedger>
     */
    private static function commandHandler(): Closure
    {
        return
            /**
             * @return EntityEffect<WalletLedger>
             */
            static fn(ActorContext $ctx, LedgerCommand $cmd, WalletLedger $ledger): EntityEffect => match (true) {
                $cmd instanceof RecordLedger => self::applyAndPersist($ledger, $cmd),
            };
    }

    /** @return EntityEffect<WalletLedger> */
    private static function applyAndPersist(WalletLedger $ledger, RecordLedger $cmd): EntityEffect
    {
        $now = new DateTimeImmutable();

        match ($cmd->kind) {
            LedgerKind::Deposit  => $ledger->recordDeposit($cmd->amountCents, $now),
            LedgerKind::Withdraw => $ledger->recordWithdraw($cmd->amountCents, $now),
        };

        // Stage a new entry on the ledger — the bidirectional relation +
        // EM->persist on the entry happens on the next persist effect.
        $ledger->appendEntry(new LedgerEntry($ledger, $cmd->kind, $cmd->amountCents, $now));

        return EntityEffect::persist();
    }
}
