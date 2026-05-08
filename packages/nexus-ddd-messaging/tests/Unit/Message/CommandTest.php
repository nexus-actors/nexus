<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Message;

use Monadial\Nexus\Ddd\Messaging\Message\Command;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class CommandTest extends TestCase
{
    #[Test]
    public function commandIsAnInterfaceWithNoMethods(): void
    {
        $reflection = new ReflectionClass(Command::class);
        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }
}
