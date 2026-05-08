<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Context;

use Monadial\Nexus\Ddd\Messaging\Context\ContextStorage;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversNothing]
final class ContextStorageInterfaceTest extends TestCase
{
    #[Test]
    public function exposesThreeMethods(): void
    {
        $reflection = new ReflectionClass(ContextStorage::class);
        self::assertTrue($reflection->isInterface());

        $methodNames = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            $reflection->getMethods(),
        );
        sort($methodNames);

        self::assertSame(['current', 'pop', 'push'], $methodNames);
    }
}
