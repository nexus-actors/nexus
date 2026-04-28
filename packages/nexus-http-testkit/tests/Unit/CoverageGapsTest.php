<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\TestKit\Tests\Unit;

use Fiber;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\TestKit\RouteResult;
use Monadial\Nexus\Http\TestKit\RouteTestKit;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\header;
use function Monadial\Nexus\Http\path;

#[CoversClass(RouteResult::class)]
#[CoversClass(RouteTestKit::class)]
final class CoverageGapsTest extends TestCase
{
    #[Test]
    public function with_header_threads_header_into_request(): void
    {
        $route = get(static fn() => path('h', static fn() => header(
            'X-Token',
            static fn(string $tok) => complete(['token' => $tok]),
        )));

        $result = RouteTestKit::route($route)
            ->withHeader('X-Token', 'abc123')
            ->get('/h')
            ->run();

        self::assertSame(200, $result->status());
        self::assertSame(['token' => 'abc123'], $result->jsonBody());
    }

    #[Test]
    public function header_returns_header_line(): void
    {
        $route = get(static fn() => path('h', static fn() => complete(['ok' => true])));

        $result = RouteTestKit::route($route)
            ->get('/h')
            ->run();

        self::assertSame('application/json', $result->header('Content-Type'));
    }

    #[Test]
    public function json_body_throws_when_response_is_not_json(): void
    {
        $response = new Response(200, [], 'plain text');
        $result = new RouteResult($response);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('response body is not a JSON object/array');
        $result->jsonBody();
    }

    #[Test]
    public function map_handles_thrown_error_via_error_mapper(): void
    {
        $route = get(static fn() => path('boom', static fn() => complete(static function (): void {
            throw new RuntimeException('explode');
        })));

        $result = RouteTestKit::route($route)
            ->get('/boom')
            ->run();

        self::assertSame(500, $result->status());
    }

    #[Test]
    public function with_system_handles_thrown_error_via_error_mapper(): void
    {
        $route = get(static fn() => path('boom', static fn() => complete(static function (): void {
            throw new RuntimeException('explode-with-system');
        })));

        $system = ActorSystem::create(
            'test',
            new StepRuntime(),
        );

        $result = RouteTestKit::route($route)
            ->withSystem($system)
            ->get('/boom')
            ->run();

        self::assertSame(500, $result->status());
    }

    #[Test]
    public function with_system_drives_fiber_runtime(): void
    {
        $route = get(static fn() => path('hi', static fn() => complete(['ok' => true])));
        $system = ActorSystem::create(
            'test',
            new FiberRuntime(),
        );

        $result = RouteTestKit::route($route)
            ->withSystem($system)
            ->get('/hi')
            ->run();

        self::assertSame(200, $result->status());
        self::assertSame(['ok' => true], $result->jsonBody());
    }

    #[Test]
    public function with_system_drives_fiber_runtime_when_route_suspends(): void
    {
        // Build a route whose body suspends the fiber once before returning.
        // This forces driveRuntime() to enter the FiberRuntime branch
        // (shutdown/run + resume).
        $route = get(static fn() => path('hi', static fn() => complete(static function () {
            Fiber::suspend();

            return ['ok' => true];
        })));

        $system = ActorSystem::create(
            'test',
            new FiberRuntime(),
        );

        $result = RouteTestKit::route($route)
            ->withSystem($system)
            ->get('/hi')
            ->run();

        self::assertSame(200, $result->status());
        self::assertSame(['ok' => true], $result->jsonBody());
    }
}
