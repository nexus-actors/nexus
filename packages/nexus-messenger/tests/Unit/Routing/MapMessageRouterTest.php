<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Routing;

use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(MapMessageRouter::class)]
#[CoversClass(Route::class)]
final class MapMessageRouterTest extends TestCase
{
    #[Test]
    public function routesByExactMessageClass(): void
    {
        $target = new DeadLetterRef();
        $router = new MapMessageRouter(Route::to(stdClass::class, $target));
        $message = new stdClass();

        self::assertSame($target, $router->route($message, new Envelope($message)));
    }

    #[Test]
    public function returnsNullForUnregisteredClass(): void
    {
        $router = new MapMessageRouter();
        $message = new stdClass();

        self::assertNull($router->route($message, new Envelope($message)));
    }

    #[Test]
    public function lastRouteForTheSameClassWins(): void
    {
        $first = new DeadLetterRef();
        $second = new DeadLetterRef();
        $router = new MapMessageRouter(
            Route::to(stdClass::class, $first),
            Route::to(stdClass::class, $second),
        );
        $message = new stdClass();

        self::assertSame($second, $router->route($message, new Envelope($message)));
    }
}
