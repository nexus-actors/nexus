<?php

declare(strict_types=1);

// phpcs:disable SlevomatCodingStandard.TypeHints.PropertyTypeHint -- anonymous Swoole\Http\Request subclasses cannot redeclare native types on parent's untyped properties

namespace Monadial\Nexus\Http\Server\Swoole\Tests\Unit\Bridge;

use Monadial\Nexus\Http\Server\Swoole\Bridge\SwooleRequestTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;

#[CoversClass(SwooleRequestTranslator::class)]
final class SwooleRequestTranslatorTest extends TestCase
{
    #[Test]
    public function maps_method_uri_and_query(): void
    {
        $req = new class extends SwooleRequest {
            public $server = [
                'query_string'    => 'q=phpunit&n=10',
                'request_method'  => 'GET',
                'request_uri'     => '/users/42',
                'server_protocol' => 'HTTP/1.1',
            ];

            public $header = ['host' => 'localhost:8080'];

            public $get = ['q' => 'phpunit', 'n' => '10'];

            public function rawContent(): string|false
            {
                return '';
            }
        };

        $psr7 = SwooleRequestTranslator::toPsr7($req);

        self::assertSame('GET', $psr7->getMethod());
        self::assertSame('/users/42', $psr7->getUri()->getPath());
        self::assertSame('q=phpunit&n=10', $psr7->getUri()->getQuery());
        self::assertSame(['q' => 'phpunit', 'n' => '10'], $psr7->getQueryParams());
        self::assertSame('localhost:8080', $psr7->getHeaderLine('host'));
    }

    #[Test]
    public function maps_post_body_as_parsed_body(): void
    {
        $req = new class extends SwooleRequest {
            public $server = ['request_method' => 'POST', 'request_uri' => '/u'];

            public $header = [];

            public $post = ['name' => 'tomas'];

            public function rawContent(): string|false
            {
                return 'name=tomas';
            }
        };

        $psr7 = SwooleRequestTranslator::toPsr7($req);

        self::assertSame(['name' => 'tomas'], $psr7->getParsedBody());
        self::assertSame('name=tomas', (string) $psr7->getBody());
    }

    #[Test]
    public function maps_cookies(): void
    {
        $req = new class extends SwooleRequest {
            public $server = ['request_method' => 'GET', 'request_uri' => '/'];

            public $header = [];

            public $cookie = ['session' => 'abc123'];

            public function rawContent(): string|false
            {
                return '';
            }
        };

        $psr7 = SwooleRequestTranslator::toPsr7($req);

        self::assertSame(['session' => 'abc123'], $psr7->getCookieParams());
    }
}
