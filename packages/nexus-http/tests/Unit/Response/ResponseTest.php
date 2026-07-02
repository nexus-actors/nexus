<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Response;

use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    #[Test]
    public function ok_returns_empty_200(): void
    {
        $response = Response::ok();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function no_content_returns_204(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function created_returns_201_with_location_header(): void
    {
        $response = Response::created('/users/42');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/users/42', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function not_found_returns_404_with_message(): void
    {
        $response = Response::notFound('User not found');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('User not found', (string) $response->getBody());
    }

    #[Test]
    public function bad_request_returns_400(): void
    {
        $response = Response::badRequest('invalid');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function gateway_timeout_returns_504(): void
    {
        self::assertSame(504, Response::gatewayTimeout()->getStatusCode());
    }

    #[Test]
    public function service_unavailable_returns_503_with_retry_after_header(): void
    {
        $response = Response::serviceUnavailable(Duration::seconds(1));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
    }

    #[Test]
    public function internal_server_error_returns_500(): void
    {
        self::assertSame(500, Response::internalServerError()->getStatusCode());
    }
}
