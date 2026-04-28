<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Swoole\Tests\Unit;

use Monadial\Nexus\Http\Swoole\SwooleRequestConverter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwRequest;

#[CoversClass(SwooleRequestConverter::class)]
final class SwooleRequestConverterTest extends TestCase
{
    #[Test]
    public function converts_get_request_with_query_and_headers(): void
    {
        $sw = new SwRequest();
        $sw->server = [
            'query_string'   => 'limit=20',
            'remote_addr'    => '127.0.0.1',
            'request_method' => 'GET',
            'request_uri'    => '/orders',
        ];
        $sw->header = ['accept' => 'application/json', 'host' => 'localhost'];
        $sw->get = ['limit' => '20'];

        $psr = (new SwooleRequestConverter())->toPsrRequest($sw);

        self::assertSame('GET', $psr->getMethod());
        self::assertSame('/orders', $psr->getUri()->getPath());
        self::assertSame('application/json', $psr->getHeaderLine('Accept'));
        self::assertSame(['limit' => '20'], $psr->getQueryParams());
    }

    #[Test]
    public function converts_post_with_body(): void
    {
        $sw = new SwRequest();
        $sw->server = ['request_method' => 'POST', 'request_uri' => '/orders'];
        $sw->header = ['content-type' => 'application/json'];

        $psr = (new SwooleRequestConverter())->toPsrRequest($sw);

        self::assertSame('POST', $psr->getMethod());
        self::assertSame('application/json', $psr->getHeaderLine('Content-Type'));
    }
}
