<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Specification;

use Fp\Functional\Either\Either;

/**
 * @psalm-api
 *
 * @template T
 *
 * Specification that returns reasons-for-failure rather than just bool. Used
 * for business rules where the caller needs to surface the WHY (validation
 * errors, UI form errors, API responses).
 */
interface RichSpecification
{
    /**
     * @param T $candidate
     * @return Either<array<int, Failure>, T> Left = failure reasons; Right = candidate
     */
    public function evaluate(mixed $candidate): Either;

    /** @return Specification<T> bool projection */
    public function asSpecification(): Specification;
}
