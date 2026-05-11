<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuildResult;
use Monadial\Nexus\Ddd\Bus\Routing\CustomMiddlewareRegistration;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(BusBuildResult::class)]
final class BusBuildResultTest extends TestCase
{
    #[Test]
    public function fieldsAreExposedAsConstructed(): void
    {
        $entry = new ResolvedAttributesEntry(
            handlerClass: 'App\\Handler\\PlaceOrderHandler',
            attributes: [],
            authorizeBeforeValidate: false,
            idempotencyOptedOut: false,
        );
        $index = new HandlerAttributeIndex([stdClass::class => $entry]);
        $handlerMap = [stdClass::class => 'App\\Handler\\PlaceOrderHandler'];
        $registration = new CustomMiddlewareRegistration(new RecordingMiddleware('m'), PipelineStage::Validation);

        $result = new BusBuildResult($index, $handlerMap, [$registration]);

        self::assertSame($index, $result->index);
        self::assertSame($handlerMap, $result->handlerMap);
        self::assertSame([$registration], $result->customMiddlewares);
    }

    #[Test]
    public function supportsEmptyConstruction(): void
    {
        $index = new HandlerAttributeIndex([]);

        $result = new BusBuildResult($index, [], []);

        self::assertSame($index, $result->index);
        self::assertSame([], $result->handlerMap);
        self::assertSame([], $result->customMiddlewares);
    }
}
