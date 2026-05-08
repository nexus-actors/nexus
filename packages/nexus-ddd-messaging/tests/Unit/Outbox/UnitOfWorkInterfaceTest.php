<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Outbox;

use Monadial\Nexus\Ddd\Messaging\Outbox\UnitOfWork;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversNothing]
final class UnitOfWorkInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDeclaresRequiredMethods(): void
    {
        $reflection = new ReflectionClass(UnitOfWork::class);

        self::assertTrue($reflection->isInterface());

        $methods = array_map(
            static fn(ReflectionMethod $m) => $m->getName(),
            $reflection->getMethods(),
        );

        self::assertContains('begin', $methods);
        self::assertContains('commit', $methods);
        self::assertContains('rollback', $methods);
        self::assertContains('outbox', $methods);
    }
}
