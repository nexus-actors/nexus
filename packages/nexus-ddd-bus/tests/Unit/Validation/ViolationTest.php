<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Validation;

use Monadial\Nexus\Ddd\Bus\Validation\Violation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Violation::class)]
final class ViolationTest extends TestCase
{
    #[Test]
    public function constructorAssignsFields(): void
    {
        $v = new Violation('not_blank', 'must not be blank', 'name');

        self::assertSame('not_blank', $v->code);
        self::assertSame('must not be blank', $v->message);
        self::assertSame('name', $v->path);
    }

    #[Test]
    public function equalsReturnsTrueOnIdentical(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $b = new Violation('not_blank', 'must not be blank', 'name');

        self::assertTrue($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseOnDifferingCode(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $b = new Violation('too_short', 'must not be blank', 'name');

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseOnDifferingMessage(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $b = new Violation('not_blank', 'cannot be empty', 'name');

        self::assertFalse($a->equals($b));
    }

    #[Test]
    public function equalsReturnsFalseOnDifferingPath(): void
    {
        $a = new Violation('not_blank', 'must not be blank', 'name');
        $b = new Violation('not_blank', 'must not be blank', 'email');

        self::assertFalse($a->equals($b));
    }
}
