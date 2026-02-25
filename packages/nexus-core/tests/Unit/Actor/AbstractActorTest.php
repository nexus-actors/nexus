<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\AbstractActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractActor::class)]
final class AbstractActorTest extends TestCase
{
    #[Test]
    public function abstractActorImplementsActorHandler(): void
    {
        $actor = new class extends AbstractActor {
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return Behavior::same();
            }
        };

        self::assertInstanceOf(ActorHandler::class, $actor);
        self::assertInstanceOf(AbstractActor::class, $actor);
    }

    #[Test]
    public function lifecycleHooksHaveDefaultNoOpImplementation(): void
    {
        $actor = new class extends AbstractActor {
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return Behavior::same();
            }
        };

        $ctx = $this->createStub(ActorContext::class);
        $actor->onPreStart($ctx);
        $actor->onPostStop($ctx);

        // No-ops by default — reaching here without exception means success
        self::assertTrue(true);
    }

    #[Test]
    public function lifecycleHooksCanBeOverridden(): void
    {
        $actor = new class extends AbstractActor {
            public bool $preStartCalled = false;
            public bool $postStopCalled = false;

            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return Behavior::same();
            }

            public function onPreStart(ActorContext $ctx): void
            {
                $this->preStartCalled = true;
            }

            public function onPostStop(ActorContext $ctx): void
            {
                $this->postStopCalled = true;
            }
        };

        $ctx = $this->createStub(ActorContext::class);
        self::assertFalse($actor->preStartCalled);
        self::assertFalse($actor->postStopCalled);

        $actor->onPreStart($ctx);
        self::assertTrue($actor->preStartCalled);

        $actor->onPostStop($ctx);
        self::assertTrue($actor->postStopCalled);
    }
}
