<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;
use Monadial\Nexus\Ddd\Bus\Routing\CustomMiddlewareRegistration;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomMiddlewareRegistration::class)]
final class CustomMiddlewareRegistrationTest extends TestCase
{
    #[Test]
    public function fieldsAreExposedAsConstructedWithStage(): void
    {
        $middleware = new RecordingMiddleware('m');

        $registration = new CustomMiddlewareRegistration($middleware, PipelineStage::Validation);

        self::assertSame($middleware, $registration->middleware);
        self::assertSame(PipelineStage::Validation, $registration->before);
    }

    #[Test]
    public function fieldsAreExposedAsConstructedWithNullStage(): void
    {
        $middleware = new RecordingMiddleware('m');

        $registration = new CustomMiddlewareRegistration($middleware, null);

        self::assertSame($middleware, $registration->middleware);
        self::assertNull($registration->before);
    }
}
