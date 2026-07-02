<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Response;

use Monadial\Nexus\Http\Response\JsonResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonResponse::class)]
final class JsonResponseTest extends TestCase
{
    #[Test]
    public function ok_serializes_array_as_json(): void
    {
        $response = JsonResponse::ok(['name' => 'Tomas', 'count' => 3]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"Tomas","count":3}', (string) $response->getBody());
    }

    #[Test]
    public function ok_serializes_scalar(): void
    {
        $response = JsonResponse::ok(42);

        self::assertSame('42', (string) $response->getBody());
    }

    #[Test]
    public function created_returns_201_with_location_and_body(): void
    {
        $response = JsonResponse::created(['id' => 7], '/users/7');

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/users/7', $response->getHeaderLine('Location'));
        self::assertSame('{"id":7}', (string) $response->getBody());
    }
}
