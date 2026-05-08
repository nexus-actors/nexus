<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Retry;

use Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversNothing]
final class BackoffStrategyInterfaceTest extends TestCase
{
    #[Test]
    public function interfaceDeclaresDelayFor(): void
    {
        $reflection = new ReflectionClass(BackoffStrategy::class);

        self::assertTrue($reflection->isInterface());
        self::assertTrue($reflection->hasMethod('delayFor'));
    }
}
