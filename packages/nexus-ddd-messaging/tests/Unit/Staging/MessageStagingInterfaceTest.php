<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Staging;

use Monadial\Nexus\Ddd\Messaging\Staging\MessageStaging;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversNothing]
final class MessageStagingInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDeclaresRequiredMethods(): void
    {
        $reflection = new ReflectionClass(MessageStaging::class);

        self::assertTrue($reflection->isInterface());

        $methods = array_map(
            static fn(ReflectionMethod $m) => $m->getName(),
            $reflection->getMethods(),
        );

        self::assertContains('appendCommand', $methods);
        self::assertContains('appendEvent', $methods);
        self::assertContains('flush', $methods);
        self::assertContains('discard', $methods);
    }
}
