<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * @template T of object
 * @psalm-api
 */
final readonly class CreateIfMissing implements EntityReplayPolicy
{
    /**
     * @param Closure(mixed): T $factory
     */
    public function __construct(private Closure $factory) {}

    /**
     * @template TEntity of object
     * @param class-string<TEntity> $entityClass
     * @return TEntity
     */
    #[Override]
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): object
    {
        $existing = $em->find($entityClass, $id);

        if ($existing !== null) {
            return $existing;
        }

        /**
         * @var TEntity $fresh resolve() is always invoked with $entityClass
         *      identical to the class produced by $factory — both are wired
         *      from the same T by EntityBehaviorBuilder/EntityRefFactory.
         */
        $fresh = ($this->factory)($id);
        $em->persist($fresh);

        return $fresh;
    }
}
