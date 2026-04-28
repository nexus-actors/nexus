<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\pathEnd;
use function Monadial\Nexus\Http\pathPrefix;

final class PathPrefixTest extends TestCase
{
    #[Test]
    public function path_prefix_consumes_one_segment(): void
    {
        $route = pathPrefix('orders', static fn() => get(static fn() => complete(['list'])));

        self::assertNotNull(($route->run)(CtxFactory::with('GET', '/orders/42')));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/payments')));
    }

    #[Test]
    public function path_end_only_completes_when_path_is_fully_consumed(): void
    {
        $route = pathPrefix(
            'orders',
            static fn() => pathEnd(static fn() => get(static fn() => complete(['list']))),
        );

        self::assertNotNull(($route->run)(CtxFactory::with('GET', '/orders')));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/orders/42')));
    }
}
