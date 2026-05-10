<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Bus\Exception\RetryableFailure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class RetryableFailureTest extends TestCase
{
    #[Test]
    public function existsAsInterfaceWithNoMethods(): void
    {
        $reflection = new ReflectionClass(RetryableFailure::class);

        self::assertTrue($reflection->isInterface());
        self::assertSame([], $reflection->getMethods());
    }
}
