<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Handler;

use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class CommandHandlerTest extends TestCase
{
    #[Test]
    public function commandHandlerIsAMarkerInterface(): void
    {
        $reflection = new ReflectionClass(CommandHandler::class);
        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }
}
