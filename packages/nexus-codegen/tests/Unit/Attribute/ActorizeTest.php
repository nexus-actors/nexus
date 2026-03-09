<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Attribute;

use Monadial\Nexus\Codegen\Attribute\Actorize;
use Monadial\Nexus\Core\Supervision\StrategyType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Actorize::class)]
final class ActorizeTest extends TestCase
{
    #[Test]
    public function defaults_are_applied(): void
    {
        $attr = new Actorize();

        self::assertTrue($attr->async);
        self::assertSame(StrategyType::OneForOne, $attr->supervision);
        self::assertSame(5, $attr->timeout);
        self::assertNull($attr->reset);
        self::assertNull($attr->namespace);
    }

    #[Test]
    public function values_are_set(): void
    {
        $attr = new Actorize(
            async: false,
            supervision: StrategyType::ExponentialBackoff,
            timeout: 10,
            reset: true,
            namespace: 'App\\Gen',
        );

        self::assertFalse($attr->async);
        self::assertSame(StrategyType::ExponentialBackoff, $attr->supervision);
        self::assertSame(10, $attr->timeout);
        self::assertTrue($attr->reset);
        self::assertSame('App\\Gen', $attr->namespace);
    }
}
