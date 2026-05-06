<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Core\Tests\Unit\Identity;

use Monadial\Nexus\Ddd\Core\Identity\Identifiable;
use Monadial\Nexus\Ddd\Core\Identity\Identifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class IdentifierContractTest extends TestCase
{
    #[Test]
    public function identifierInterfaceDeclaresExpectedMethods(): void
    {
        $reflection = new ReflectionClass(Identifier::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('value'));
        self::assertTrue($reflection->hasMethod('equals'));
        self::assertTrue($reflection->hasMethod('fromString'));

        $value = $reflection->getMethod('value');
        self::assertSame('string', (string) $value->getReturnType());

        $fromString = $reflection->getMethod('fromString');
        self::assertTrue($fromString->isStatic());
    }

    #[Test]
    public function identifiableInterfaceRequiresId(): void
    {
        $reflection = new ReflectionClass(Identifiable::class);
        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('id'));
    }
}
