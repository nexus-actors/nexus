<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Envelope;

use Monadial\Nexus\Ddd\Messaging\Envelope\Stamp;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class StampTest extends TestCase
{
    #[Test]
    public function stampIsAnInterfaceWithNoMethods(): void
    {
        $reflection = new ReflectionClass(Stamp::class);
        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }
}
