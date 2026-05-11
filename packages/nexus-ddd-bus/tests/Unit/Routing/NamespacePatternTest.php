<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Routing\NamespacePattern;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingResolution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(NamespacePattern::class)]
#[CoversClass(RoutingResolution::class)]
final class NamespacePatternTest extends TestCase
{
    #[Test]
    public function emptyPatternListResolvesToNone(): void
    {
        $strategy = new NamespacePattern();

        self::assertTrue($strategy->resolve(stdClass::class)->isNone());
    }

    #[Test]
    public function matchingPatternResolvesToSome(): void
    {
        $strategy = new NamespacePattern()->namespace('App\\Orders\\*', 'orders');

        $result = $strategy->resolve('App\\Orders\\PlaceOrder');

        self::assertTrue($result->isSome());

        $resolution = $result->getUnsafe();
        self::assertSame('orders', $resolution->busName);
        self::assertSame(NamespacePattern::class, $resolution->resolvedBy);
    }

    #[Test]
    public function nonMatchingPatternResolvesToNone(): void
    {
        $strategy = new NamespacePattern()->namespace('App\\Orders\\*', 'orders');

        self::assertTrue($strategy->resolve('App\\Reporting\\GenerateReport')->isNone());
    }

    #[Test]
    public function firstMatchWins(): void
    {
        $strategy = new NamespacePattern()
            ->namespace('App\\Orders\\*', 'orders')
            ->namespace('App\\*', 'fallback');

        self::assertSame(
            'orders',
            $strategy->resolve('App\\Orders\\PlaceOrder')->getUnsafe()->busName,
        );
    }
}
