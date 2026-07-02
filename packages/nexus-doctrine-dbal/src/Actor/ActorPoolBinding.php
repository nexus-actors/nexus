<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Actor;

use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;

/**
 * Ergonomic carrier for injecting a ConnectionPool into actors via Props::fromFactory().
 *
 * Plan 2 (nexus-doctrine-orm) will introduce a sibling OrmActorPoolBinding that composes
 * this one with an EntityManagerPool — so this binding stays DBAL-only and unaware of ORM.
 *
 * @psalm-api
 */
final readonly class ActorPoolBinding
{
    public function __construct(public ConnectionPool $connPool) {}
}
