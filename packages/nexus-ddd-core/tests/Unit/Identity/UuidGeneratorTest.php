<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\UuidGenerator;
use Monadial\Nexus\Ddd\Core\Tests\Support\TestUuidId;
use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UuidGenerator::class)]
final class UuidGeneratorTest extends TestCase
{
    #[Test]
    public function nextReturnsConfiguredConcreteIdentifier(): void
    {
        $id = (new UuidGenerator(TestUuidId::class))->next();
        self::assertInstanceOf(TestUuidId::class, $id);
        self::assertInstanceOf(UuidValue::class, $id);
        self::assertSame(36, strlen($id->value()));
    }
}
