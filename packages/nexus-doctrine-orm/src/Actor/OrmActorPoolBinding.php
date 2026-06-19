<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Actor;

use Monadial\Nexus\Doctrine\Dbal\Actor\ActorPoolBinding;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;

/**
 * Carries both connection and EM pool. Composes Plan 1's DBAL-only binding
 * via a `base` field so DBAL stays independent of the ORM package.
 *
 * @psalm-api
 */
final readonly class OrmActorPoolBinding
{
    public function __construct(public ActorPoolBinding $base, public EntityManagerPool $emPool) {}
}
