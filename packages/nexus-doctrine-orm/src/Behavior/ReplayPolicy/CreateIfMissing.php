<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Override;

/**
 * @template T of object
 * @template-implements EntityReplayPolicy<T>
 * @psalm-api
 */
final readonly class CreateIfMissing implements EntityReplayPolicy
{
    /**
     * @param Closure(mixed): T $factory
     */
    public function __construct(private Closure $factory) {}

    /**
     * @psalm-suppress InvalidReturnType, InvalidReturnStatement
     */
    #[Override]
    public function resolve(EntityManagerInterface $em, string $entityClass, mixed $id): object
    {
        $existing = $em->find($entityClass, $id);

        if ($existing !== null) {
            return $existing;
        }

        $fresh = ($this->factory)($id);
        $em->persist($fresh);

        return $fresh;
    }
}
