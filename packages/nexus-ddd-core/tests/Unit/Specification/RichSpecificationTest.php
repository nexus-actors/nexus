<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Specification;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Core\Specification\AbstractRichSpecification;
use Monadial\Nexus\Ddd\Core\Specification\Failure;
use Monadial\Nexus\Ddd\Core\Specification\Specification;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RichSpecificationTest extends TestCase
{
    #[Test]
    public function evaluateReturnsRightOnSuccess(): void
    {
        $spec = new IsPositiveRich();
        $result = $spec->evaluate(5);
        self::assertInstanceOf(Either::class, $result);
        self::assertTrue($result->isRight());
        self::assertSame(5, $result->get());
    }

    #[Test]
    public function evaluateReturnsLeftOnFailureWithReasons(): void
    {
        $spec = new IsPositiveRich();
        $result = $spec->evaluate(-1);
        self::assertTrue($result->isLeft());

        /** @var array<int, Failure> $failures */
        $failures = $result->get();
        self::assertNotEmpty($failures);
        self::assertSame('value', $failures[0]->field);
        self::assertSame('not_positive', $failures[0]->code);
    }

    #[Test]
    public function asSpecificationProjectsToBool(): void
    {
        $rich = new IsPositiveRich();
        $bool = $rich->asSpecification();
        self::assertInstanceOf(Specification::class, $bool);
        self::assertTrue($bool->isSatisfiedBy(5));
        self::assertFalse($bool->isSatisfiedBy(-1));
    }
}

/** @extends AbstractRichSpecification<int> */
final class IsPositiveRich extends AbstractRichSpecification
{
    #[Override]
    public function evaluate(mixed $candidate): Either
    {
        if (is_int($candidate) && $candidate > 0) {
            return Either::right($candidate);
        }

        return Either::left([new Failure('value', 'not_positive', 'must be positive int')]);
    }
}
