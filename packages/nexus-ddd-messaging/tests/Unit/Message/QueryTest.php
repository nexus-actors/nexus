<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Message;

use Monadial\Nexus\Ddd\Messaging\Message\Query;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class QueryTest extends TestCase
{
    #[Test]
    public function queryIsAnInterfaceWithNoMethods(): void
    {
        $reflection = new ReflectionClass(Query::class);
        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }

    #[Test]
    public function queryCarriesTResultTemplateInDocComment(): void
    {
        $reflection = new ReflectionClass(Query::class);
        $doc = $reflection->getDocComment();
        self::assertIsString($doc);
        self::assertStringContainsString('@template TResult', $doc);
    }
}
