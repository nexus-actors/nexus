<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Attribute;

use Attribute;
use Monadial\Nexus\Ddd\Bus\Attribute\IdempotencyKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(IdempotencyKey::class)]
final class IdempotencyKeyTest extends TestCase
{
    #[Test]
    public function targetsClasses(): void
    {
        $reflection = new ReflectionClass(IdempotencyKey::class);
        $attrs = $reflection->getAttributes(Attribute::class);

        self::assertCount(1, $attrs);

        $meta = $attrs[0]->newInstance();

        self::assertSame(Attribute::TARGET_CLASS, $meta->flags);
    }

    #[Test]
    public function constructsWithFieldName(): void
    {
        $attr = new IdempotencyKey(field: 'clientRequestId');

        self::assertSame('clientRequestId', $attr->field);
    }
}
