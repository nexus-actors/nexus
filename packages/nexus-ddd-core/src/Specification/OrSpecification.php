<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

use Override;

/**
 * @psalm-api
 *
 * @template T
 * @extends AbstractSpecification<T>
 */
final class OrSpecification extends AbstractSpecification
{
    /**
     * @param Specification<T> $left
     * @param Specification<T> $right
     */
    public function __construct(private readonly Specification $left, private readonly Specification $right,) {}

    #[Override]
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->left->isSatisfiedBy($candidate) || $this->right->isSatisfiedBy($candidate);
    }
}
