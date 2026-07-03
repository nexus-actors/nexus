<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Boot;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DatabaseObjectExistsException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Tools\SchemaTool;
use Monadial\Nexus\Doctrine\Orm\Pool\DefaultEntityManagerFactory;
use Monadial\Nexus\Persistence\Doctrine\Entity\EventEntry;
use Monadial\Nexus\Persistence\Doctrine\Entity\SnapshotEntry;

/**
 * Idempotent schema sync — production would use a migration tool. Every
 * worker thread races this on startup; both `UniqueConstraintViolationException`
 * (row-level race) and `DatabaseObjectExistsException` (DDL race,
 * SQLSTATE 42P07 on Postgres) are tolerated so the loser doesn't crash.
 */
final class SchemaBootstrap
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
    public static function sync(array $connParams, Configuration $ormConfig): void
    {
        $conn = DriverManager::getConnection($connParams);

        try {
            $em = new DefaultEntityManagerFactory($ormConfig)->create($conn);

            try {
                new SchemaTool($em)->updateSchema([
                    $em->getClassMetadata(EventEntry::class),
                    $em->getClassMetadata(SnapshotEntry::class),
                ]);
            } catch (DatabaseObjectExistsException | UniqueConstraintViolationException) {
                // Lost the race with another worker; the winner already
                // created the schema. Fine.
            } finally {
                $em->close();
            }
        } finally {
            $conn->close();
        }
    }
}
