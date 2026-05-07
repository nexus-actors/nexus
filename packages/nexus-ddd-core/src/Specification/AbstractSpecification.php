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
    #[\NoDiscard('combinators return a new Specification — ignoring the result drops the composition')]
    public function and(Specification $other): Specification
    {
        return new AndSpecification($this, $other);
    }

    #[\Override]
    #[\NoDiscard('combinators return a new Specification — ignoring the result drops the composition')]
    public function or(Specification $other): Specification
    {
        return new OrSpecification($this, $other);
    }

    #[\Override]
    #[\NoDiscard('combinators return a new Specification — ignoring the result drops the composition')]
    public function not(): Specification
    {
        return new NotSpecification($this);
    }
}
