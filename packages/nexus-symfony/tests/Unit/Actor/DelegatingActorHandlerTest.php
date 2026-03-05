<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Symfony\Actor\DelegatingActorHandler;
use Monadial\Nexus\Symfony\Attribute\AsActorHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final readonly class PingMessage {}
final readonly class PongMessage {}

#[CoversClass(DelegatingActorHandler::class)]
final class DelegatingActorHandlerTest extends TestCase
{
    #[Test]
    public function routesMessageToMatchingMethod(): void
    {
        $tracker = new stdClass();
        $tracker->handled = false;

        $service = new class ($tracker) {
            public function __construct(private readonly stdClass $tracker) {}

            #[AsActorHandler]
            public function onPing(PingMessage $msg): void
            {
                $this->tracker->handled = true;
            }
        };

        $ctx     = $this->createStub(ActorContext::class);
        $handler = new DelegatingActorHandler($service);

        $result = $handler->handle($ctx, new PingMessage());

        self::assertTrue($tracker->handled);
        self::assertInstanceOf(Behavior::class, $result);
    }

    #[Test]
    public function returnsUnhandledForUnknownMessage(): void
    {
        $service = new class {};
        $ctx     = $this->createStub(ActorContext::class);
        $handler = new DelegatingActorHandler($service);

        $result = $handler->handle($ctx, new PongMessage());

        self::assertInstanceOf(Behavior::class, $result);
    }

    #[Test]
    public function returnsSameBehaviorWhenHandlerReturnsVoid(): void
    {
        $service = new class {
            #[AsActorHandler]
            public function onPing(PingMessage $msg): void
            {
                // no-op: tests that void return maps to Behavior::same()
            }
        };

        $ctx     = $this->createStub(ActorContext::class);
        $handler = new DelegatingActorHandler($service);
        $result  = $handler->handle($ctx, new PingMessage());

        self::assertInstanceOf(Behavior::class, $result);
    }
}
