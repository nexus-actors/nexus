<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Messaging\Exception\MessagingException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(MessagingException::class)]
final class MessagingExceptionTest extends TestCase
{
    #[Test]
    public function isAbstractAndExtendsRuntimeExceptionDirectly(): void
    {
        $reflection = new ReflectionClass(MessagingException::class);

        self::assertTrue($reflection->isAbstract());
        self::assertSame(RuntimeException::class, $reflection->getParentClass()->getName());
    }
}
