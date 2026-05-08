<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Inbox;

use Monadial\Nexus\Ddd\Messaging\Inbox\MessageInbox;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversNothing]
final class MessageInboxInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDeclaresRequiredMethods(): void
    {
        $reflection = new ReflectionClass(MessageInbox::class);

        self::assertTrue($reflection->isInterface());

        $methods = array_map(
            static fn(ReflectionMethod $m) => $m->getName(),
            $reflection->getMethods(),
        );

        self::assertContains('tryReserve', $methods);
        self::assertContains('markProcessed', $methods);
        self::assertContains('release', $methods);
    }
}
