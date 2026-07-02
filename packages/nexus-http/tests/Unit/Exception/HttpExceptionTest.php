<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Exception;

use Monadial\Nexus\Http\Exception\HttpException;
use Monadial\Nexus\Http\Exception\MethodNotAllowedException;
use Monadial\Nexus\Http\Exception\RouteNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HttpException::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(RouteNotFoundException::class)]
final class HttpExceptionTest extends TestCase
{
    #[Test]
    public function not_found_factory_returns_404(): void
    {
        $e = HttpException::notFound('User');
        self::assertSame(404, $e->status);
        self::assertSame('User', $e->getMessage());
    }

    #[Test]
    public function method_not_allowed_carries_allow_header(): void
    {
        $e = new MethodNotAllowedException('POST', '/users', ['GET', 'PUT']);
        self::assertSame(405, $e->status);
        self::assertSame('GET, PUT', $e->headers['Allow']);
    }

    #[Test]
    public function unprocessable_entity_serializes_errors(): void
    {
        $e = HttpException::unprocessableEntity(['email' => 'invalid']);
        self::assertSame(422, $e->status);
        self::assertStringContainsString('"email":"invalid"', $e->getMessage());
    }
}
