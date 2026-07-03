<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\SharedKernel;

use InvalidArgumentException;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(TenantId::class)]
final class TenantIdTest extends TestCase
{
    #[Test]
    public function acceptsLowercaseSlug(): void
    {
        self::assertSame('acme-eu', TenantId::fromString('acme-eu')->value);
    }

    #[Test]
    public function rejectsUppercase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantId::fromString('ACME');
    }

    #[Test]
    public function rejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TenantId::fromString('');
    }

    #[Test]
    public function equalsComparesByValue(): void
    {
        self::assertTrue(TenantId::fromString('acme')->equals(TenantId::fromString('acme')));
        self::assertFalse(TenantId::fromString('acme')->equals(TenantId::fromString('umbrella')));
    }
}
