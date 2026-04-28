<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit\Tests\Unit;

use Monadial\Nexus\Http\TestKit\RouteResult;
use Monadial\Nexus\Http\TestKit\RouteTestKit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;
use function Monadial\Nexus\Http\post;
use function Monadial\Nexus\Http\rawBody;
use function strlen;

#[CoversClass(RouteTestKit::class)]
#[CoversClass(RouteResult::class)]
final class RouteTestKitTest extends TestCase
{
    #[Test]
    public function builds_get_request_and_runs_route(): void
    {
        $route = get(static fn() => path('hello', static fn() => complete(['msg' => 'hi'])));

        $result = RouteTestKit::route($route)
            ->get('/hello')
            ->run();

        self::assertSame(200, $result->status());
        self::assertSame(['msg' => 'hi'], $result->jsonBody());
    }

    #[Test]
    public function returns_404_when_route_rejects(): void
    {
        $route = get(static fn() => path('hello', static fn() => complete([])));

        $result = RouteTestKit::route($route)
            ->get('/missing')
            ->run();

        self::assertSame(404, $result->status());
    }

    #[Test]
    public function passes_request_body_for_post(): void
    {
        $route = post(static fn() => rawBody(static fn(string $body) => complete(['len' => strlen($body)])));

        $result = RouteTestKit::route($route)
            ->post('/', '{"a":1}')
            ->run();

        self::assertSame(200, $result->status());
        self::assertSame(['len' => 7], $result->jsonBody());
    }
}
