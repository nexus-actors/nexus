<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Rejection;

use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Monadial\Nexus\Http\Rejection\MethodNotAllowedRejection;
use Monadial\Nexus\Http\Rejection\RouteNotFoundRejection;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RouteRejection::class)]
#[CoversClass(RouteNotFoundRejection::class)]
#[CoversClass(MethodNotAllowedRejection::class)]
#[CoversClass(ExtractorRejection::class)]
final class RouteRejectionTest extends TestCase
{
    #[Test]
    public function route_rejection_carries_status_code_and_message(): void
    {
        $rejection = new RouteRejection('bad_request', 'broken', 400);

        self::assertSame('bad_request', $rejection->code);
        self::assertSame('broken', $rejection->getMessage());
        self::assertSame(400, $rejection->status);
    }

    #[Test]
    public function not_found_defaults_to_404(): void
    {
        $rejection = new RouteNotFoundRejection('/missing');

        self::assertSame(404, $rejection->status);
        self::assertSame('not_found', $rejection->code);
        self::assertStringContainsString('/missing', $rejection->getMessage());
    }

    #[Test]
    public function method_not_allowed_carries_allowed_methods(): void
    {
        $rejection = new MethodNotAllowedRejection('PATCH', ['GET', 'POST']);

        self::assertSame(405, $rejection->status);
        self::assertSame(['GET', 'POST'], $rejection->allowed);
    }

    #[Test]
    public function extractor_rejection_400(): void
    {
        $rejection = new ExtractorRejection('orders/abc', 'expected integer');

        self::assertSame(400, $rejection->status);
        self::assertSame('extractor_failed', $rejection->code);
        self::assertStringContainsString('orders/abc', $rejection->getMessage());
    }
}
