<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Boot;

use Doctrine\ORM\Configuration;
use Doctrine\ORM\ORMSetup;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Doctrine\Dbal\DoctrinePool;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityRefFactory;
use Monadial\Nexus\Doctrine\Orm\DoctrineEmPool;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Example\Wallet\Actor\LedgerActor;

/**
 * Per-worker Doctrine wiring. Builds the two pools and the EntityRefFactory
 * in one place so the HTTP factory can stay declarative.
 *
 * - `connPool` — DBAL `ConnectionPool` for handlers that ask for
 *   `Doctrine\DBAL\Connection $conn` (raw SQL, e.g. AdminAllLedgersHandler).
 * - `emPool`   — `EntityManagerPool` for handlers that ask for
 *   `EntityManagerInterface $em` (ORM / DQL / repositories, e.g.
 *   LedgerHandler).
 * - `ledgerFactory` — EntityRefFactory for the per-owner LedgerActor; each
 *   spawned actor owns its OWN dedicated EM + connection (NOT from the
 *   pools — that's the EntityBehavior invariant).
 *
 * Schema sync runs once per worker boot via SchemaBootstrap.
 */
final readonly class DoctrineKit
{
    public function __construct(
        public ConnectionPool $connPool,
        public EntityManagerPool $emPool,
        public EntityRefFactory $ledgerFactory,
        public Configuration $ormConfig,
    ) {}

    public static function build(WalletDbConfig $db, ActorSystem $system): self
    {
        $connParams = $db->toDbalParams();

        $ormConfig = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__) . '/Domain/Entity'],
        );
        $ormConfig->enableNativeLazyObjects(true);

        SchemaBootstrap::sync($connParams, $ormConfig);

        return new self(
            connPool: DoctrinePool::fromParams(
                name: 'wallet-dbal',
                connParams: $connParams,
                config: new PoolConfig(max: 8, minIdle: 1),
            ),
            emPool: DoctrineEmPool::forConfig(
                name: 'wallet-em',
                connParams: $connParams,
                ormSetup: $ormConfig,
                config: new EmPoolConfig(max: 8, minIdle: 1),
            ),
            ledgerFactory: LedgerActor::factory($system, $ormConfig, $connParams),
            ormConfig: $ormConfig,
        );
    }
}
