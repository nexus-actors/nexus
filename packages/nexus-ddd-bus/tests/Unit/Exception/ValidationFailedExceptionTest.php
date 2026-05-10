<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\ValidationFailedException;
use Monadial\Nexus\Ddd\Bus\Validation\Violation;
use Monadial\Nexus\Ddd\Bus\Validation\Violations;
use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationFailedException::class)]
final class ValidationFailedExceptionTest extends TestCase
{
    #[Test]
    public function withCarriesViolations(): void
    {
        $violations = new Violations([
            new Violation('not_blank', 'must not be blank', 'name'),
            new Violation('too_short', 'too short', 'email'),
        ]);

        $ex = ValidationFailedException::with($violations);

        self::assertSame($violations, $ex->violations());
    }

    #[Test]
    public function messageContainsViolationCount(): void
    {
        $violations = new Violations([
            new Violation('not_blank', 'must not be blank', 'name'),
            new Violation('too_short', 'too short', 'email'),
        ]);

        $ex = ValidationFailedException::with($violations);

        self::assertStringContainsString('2', $ex->getMessage());
    }

    #[Test]
    public function isDomainException(): void
    {
        $ex = ValidationFailedException::with(Violations::empty());

        self::assertInstanceOf(DomainException::class, $ex);
    }

    #[Test]
    public function isTerminalFailure(): void
    {
        $ex = ValidationFailedException::with(Violations::empty());

        self::assertInstanceOf(TerminalFailure::class, $ex);
    }
}
