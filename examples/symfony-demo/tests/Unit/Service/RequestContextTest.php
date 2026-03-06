<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\RequestContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestContext::class)]
final class RequestContextTest extends TestCase
{
    #[Test]
    public function constructor_generatesUlidRequestId(): void
    {
        $ctx = new RequestContext();

        self::assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $ctx->requestId);
    }

    #[Test]
    public function constructor_setsStartedAt(): void
    {
        $before = microtime(true);
        $ctx    = new RequestContext();
        $after  = microtime(true);

        self::assertGreaterThanOrEqual($before, $ctx->startedAt);
        self::assertLessThanOrEqual($after, $ctx->startedAt);
    }

    #[Test]
    public function elapsedMs_returnsPositiveValue(): void
    {
        $ctx = new RequestContext();

        self::assertGreaterThanOrEqual(0.0, $ctx->elapsedMs());
    }

    #[Test]
    public function twoInstances_haveDifferentRequestIds(): void
    {
        $a = new RequestContext();
        $b = new RequestContext();

        self::assertNotSame($a->requestId, $b->requestId);
    }
}
