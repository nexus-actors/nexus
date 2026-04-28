<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Http\Rejection\RouteRejection;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\mapRejection;
use function Monadial\Nexus\Http\mapResponse;
use function Monadial\Nexus\Http\reject;

final class MapTest extends TestCase
{
    #[Test]
    public function map_response_transforms_completed_response(): void
    {
        $route = mapResponse(
            static fn(ResponseInterface $r) => $r->withHeader('X-Wrap', 'yes'),
            static fn() => complete(['ok' => true]),
        );

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('yes', $response->getHeaderLine('X-Wrap'));
    }

    #[Test]
    public function map_rejection_converts_thrown_rejection_to_response(): void
    {
        $route = mapRejection(
            static fn(RouteRejection $r) => new Response($r->status, [], 'mapped'),
            static fn() => reject(new RouteRejection('forbidden', 'no', 403)),
        );

        $response = ($route->run)(CtxFactory::with('GET', '/'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('mapped', (string) $response->getBody());
    }
}
