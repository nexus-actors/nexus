<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Monadial\Nexus\Ddd\Bus\Middleware\EnvelopePipeline;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Middleware\PerHandlerPipeline;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class EnvelopePipelineInterfaceTest extends TestCase
{
    #[Test]
    public function middlewarePipelineImplementsEnvelopePipeline(): void
    {
        self::assertTrue(is_subclass_of(MiddlewarePipeline::class, EnvelopePipeline::class));
    }

    #[Test]
    public function perHandlerPipelineImplementsEnvelopePipeline(): void
    {
        self::assertTrue(is_subclass_of(PerHandlerPipeline::class, EnvelopePipeline::class));
    }
}
