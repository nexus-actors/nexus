<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use LogicException;
use Monadial\Nexus\Http\Extract\IntNumber;
use Monadial\Nexus\Http\Extract\UlidSegment;
use Monadial\Nexus\Http\Tests\Unit\Directive\Helpers\CtxFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Uid\Ulid;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\get;
use function Monadial\Nexus\Http\path;

final class PathTest extends TestCase
{
    #[Test]
    public function literal_only(): void
    {
        $route = path('orders', static fn() => get(static fn() => complete(['ok'])));
        self::assertInstanceOf(ResponseInterface::class, ($route->run)(CtxFactory::with('GET', '/orders')));
    }

    #[Test]
    public function literal_with_extractor(): void
    {
        $route = path(
            'orders',
            IntNumber::class,
            static fn(int $id) => get(static fn() => complete(['id' => $id])),
        );
        $response = ($route->run)(CtxFactory::with('GET', '/orders/42'));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('{"id":42}', (string) $response->getBody());
    }

    #[Test]
    public function multiple_literals_and_extractors(): void
    {
        $route = path(
            'tenant',
            UlidSegment::class,
            'orders',
            IntNumber::class,
            static fn(Ulid $tid, int $oid) => get(
                static fn() => complete(['o' => $oid, 't' => (string) $tid]),
            ),
        );

        $ulid = '01HW00000000000000000000ZZ';
        $response = ($route->run)(CtxFactory::with('GET', "/tenant/{$ulid}/orders/7"));
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertStringContainsString('"o":7', (string) $response->getBody());
    }

    #[Test]
    public function rejects_when_path_does_not_match(): void
    {
        $route = path('orders', static fn() => get(static fn() => complete(['ok'])));
        self::assertNull(($route->run)(CtxFactory::with('GET', '/payments')));
    }

    #[Test]
    public function throws_logic_exception_when_last_arg_is_not_callable(): void
    {
        $this->expectException(LogicException::class);
        path('orders');
    }
}
