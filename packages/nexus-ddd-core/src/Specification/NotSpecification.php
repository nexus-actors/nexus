<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 *
 * @template T
 * @extends AbstractSpecification<T>
 */
final class NotSpecification extends AbstractSpecification
{
    /** @param Specification<T> $inner */
    public function __construct(private readonly Specification $inner) {}

    #[\Override]
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return ! $this->inner->isSatisfiedBy($candidate);
    }
}
