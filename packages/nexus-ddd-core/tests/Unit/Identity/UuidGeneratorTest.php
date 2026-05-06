<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\UuidGenerator;
use Monadial\Nexus\Ddd\Core\Value\UuidValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UuidGenerator::class)]
final class UuidGeneratorTest extends TestCase
{
    #[Test]
    public function nextReturnsUuidValue(): void
    {
        $id = (new UuidGenerator())->next();
        self::assertInstanceOf(UuidValue::class, $id);
        self::assertSame(36, strlen($id->value()));
    }
}
