<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Specification;

use Monadial\Nexus\Ddd\Core\Specification\AbstractSpecification;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SpecificationCombinatorsTest extends TestCase
{
    #[Test]
    public function andRequiresBoth(): void
    {
        $isPositive = new IsPositive();
        $isEven = new IsEven();
        $spec = $isPositive->and($isEven);

        self::assertTrue($spec->isSatisfiedBy(4));
        self::assertFalse($spec->isSatisfiedBy(3));
        self::assertFalse($spec->isSatisfiedBy(-2));
    }

    #[Test]
    public function orRequiresEither(): void
    {
        $isPositive = new IsPositive();
        $isEven = new IsEven();
        $spec = $isPositive->or($isEven);

        self::assertTrue($spec->isSatisfiedBy(4));
        self::assertTrue($spec->isSatisfiedBy(3));
        self::assertTrue($spec->isSatisfiedBy(-2));
        self::assertFalse($spec->isSatisfiedBy(-3));
    }

    #[Test]
    public function notInverts(): void
    {
        $isPositive = new IsPositive();
        $spec = $isPositive->not();
        self::assertFalse($spec->isSatisfiedBy(1));
        self::assertTrue($spec->isSatisfiedBy(-1));
    }
}

/** @extends AbstractSpecification<int> */
final class IsPositive extends AbstractSpecification
{
    #[Override]
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_int($candidate) && $candidate > 0;
    }
}

/** @extends AbstractSpecification<int> */
final class IsEven extends AbstractSpecification
{
    #[Override]
    public function isSatisfiedBy(mixed $candidate): bool
    {
        return is_int($candidate) && $candidate % 2 === 0;
    }
}
