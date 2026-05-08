<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Handler;

use Monadial\Nexus\Ddd\Messaging\Handler\QueryHandler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class QueryHandlerTest extends TestCase
{
    #[Test]
    public function queryHandlerIsAMarkerInterfaceWithTemplate(): void
    {
        $reflection = new ReflectionClass(QueryHandler::class);
        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());

        $doc = $reflection->getDocComment();
        self::assertIsString($doc);
        self::assertStringContainsString('@template TResult', $doc);
    }
}
