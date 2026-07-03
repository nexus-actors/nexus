<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Routing;

use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;

#[CoversClass(MapMessageRouter::class)]
final class MapMessageRouterTest extends TestCase
{
    #[Test]
    public function routesByExactMessageClass(): void
    {
        $target = new DeadLetterRef();
        $router = new MapMessageRouter([stdClass::class => $target]);
        $message = new stdClass();

        self::assertSame($target, $router->route($message, new Envelope($message)));
    }

    #[Test]
    public function returnsNullForUnregisteredClass(): void
    {
        $router = new MapMessageRouter([]);
        $message = new stdClass();

        self::assertNull($router->route($message, new Envelope($message)));
    }
}
