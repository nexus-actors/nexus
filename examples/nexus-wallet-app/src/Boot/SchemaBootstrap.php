<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Example\Wallet\Domain\Entity\LedgerEntry;
use Monadial\Nexus\Example\Wallet\Domain\Entity\WalletLedger;

/**
 * Idempotent schema sync. Every worker thread runs this on startup; the
 * Postgres `pg_class` race is tolerated by catching the
 * `UniqueConstraintViolationException` that the loser sees.
 *
 * Uses a throwaway connection + EntityManager — the pooled connections
 * are not in scope yet at this point in the boot.
 */
final class SchemaBootstrap
{
    /** @param array<string, mixed> $connParams */
    public static function sync(array $connParams, Configuration $ormConfig): void
    {
        $conn = DriverManager::getConnection($connParams);

        try {
            $em = (new DefaultEntityManagerFactory($ormConfig))->create($conn);

            try {
                new SchemaTool($em)->updateSchema([
                    $em->getClassMetadata(LedgerEntry::class),
                    $em->getClassMetadata(WalletLedger::class),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another worker won the race and created the tables already. Fine.
            } finally {
                $em->close();
            }
        } finally {
            $conn->close();
        }
    }
}
