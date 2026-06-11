<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Performance\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

#[CoversNothing]
final class RouteDispatchBenchmarkTest extends TestCase
{
    /**
     * @psalm-suppress InvalidOperand $n * 0.99 mixes int and float for percentile index.
     */
    #[Test]
    public function dispatches_50_route_table_in_acceptable_time(): void
    {
        $system = ActorSystem::create('bench', new FiberRuntime());
        $app = HttpApp::create($system);

        for ($i = 0; $i < 50; $i++) {
            $app->get("/route-{$i}/{id}", static fn(): ResponseInterface => Response::ok());
        }

        $compiled = $app->compile();
        $request = new ServerRequest('GET', '/route-25/42');

        $samples = [];
        $warmup = 100;
        $n = 10_000;

        for ($i = 0; $i < $warmup; $i++) {
            $compiled->handle($request);
        }

        for ($i = 0; $i < $n; $i++) {
            $start = hrtime(true);
            $compiled->handle($request);
            $samples[] = hrtime(true) - $start;
        }

        sort($samples);
        $p99 = $samples[(int) floor($n * 0.99)];

        // 1ms per request P99 is a generous initial budget; tighten as the
        // hot path matures. The threshold is a regression guard, not a target.
        self::assertLessThan(1_000_000, $p99, "P99 = {$p99}ns");
    }
}
