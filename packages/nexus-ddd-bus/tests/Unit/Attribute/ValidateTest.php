<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Validate::class)]
final class ValidateTest extends TestCase
{
    #[Test]
    public function targetsMethods(): void
    {
        $reflection = new ReflectionClass(Validate::class);
        $attrs = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);

        $meta = $attrs[0]->newInstance();

        self::assertSame(Attribute::TARGET_METHOD, $meta->flags);
    }

    #[Test]
    public function constructsWithEmptyGroupsByDefault(): void
    {
        $attr = new Validate();

        self::assertSame([], $attr->groups);
    }

    #[Test]
    public function constructsWithExplicitGroups(): void
    {
        $attr = new Validate(groups: ['create', 'strict']);

        self::assertSame(['create', 'strict'], $attr->groups);
    }
}
