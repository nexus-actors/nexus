<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

use Fp\Functional\Either\Either;
use Override;

/**
 * @psalm-api
 *
 * @template T
 * @implements RichSpecification<T>
 */
abstract class AbstractRichSpecification implements RichSpecification
{
    /**
     * @param T $candidate
     * @return Either<array<int, Failure>, T>
     */
    #[Override]
    abstract public function evaluate(mixed $candidate): Either;

    #[Override]
    public function asSpecification(): Specification
    {
        return new RichToBoolSpecification($this);
    }
}

/**
 * @internal
 *
 * @template T
 * @extends AbstractSpecification<T>
 */
final class RichToBoolSpecification extends AbstractSpecification
{
    /** @param AbstractRichSpecification<T> $inner */
    public function __construct(private readonly AbstractRichSpecification $inner) {}

    #[Override]
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return $this->inner->evaluate($candidate)->isRight();
    }
}
