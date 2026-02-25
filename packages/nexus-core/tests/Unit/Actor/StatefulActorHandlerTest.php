<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversNothing]
final class StatefulActorHandlerTest extends TestCase
{
    #[Test]
    public function statefulActorHandlerCanBeImplemented(): void
    {
        $actor = new class implements StatefulActorHandler {
            public function initialState(): int
            {
                return 0;
            }

            public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
            {
                return BehaviorWithState::next($state + 1);
            }
        };

        self::assertInstanceOf(StatefulActorHandler::class, $actor);
        self::assertSame(0, $actor->initialState());
    }

    #[Test]
    public function handleReturnsExpectedBehaviorWithState(): void
    {
        $actor = new class implements StatefulActorHandler {
            public function initialState(): int
            {
                return 10;
            }

            public function handle(ActorContext $ctx, object $message, mixed $state): BehaviorWithState
            {
                return BehaviorWithState::next($state + 1);
            }
        };

        $ctx = $this->createStub(ActorContext::class);
        $result = $actor->handle($ctx, new stdClass(), 10);

        self::assertInstanceOf(BehaviorWithState::class, $result);
        self::assertTrue($result->state()->isSome());
        self::assertSame(11, $result->state()->get());
    }
}
