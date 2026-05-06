<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

/**
 * @psalm-api
 *
 * @template T
 * @implements Specification<T>
 */
abstract class AbstractSpecification implements Specification
{
    #[\Override]
    public function and(Specification $other): Specification
    {
        return new AndSpecification($this, $other);
    }

    #[\Override]
    public function or(Specification $other): Specification
    {
        return new OrSpecification($this, $other);
    }

    #[\Override]
    public function not(): Specification
    {
        return new NotSpecification($this);
    }
}
