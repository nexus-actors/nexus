<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorHandler::class)]
final class ActorHandlerTest extends TestCase
{
    #[Test]
    public function actorHandlerCanBeImplemented(): void
    {
        $handler = new class implements ActorHandler {
            public bool $handled = false;

            public function handle(ActorContext $ctx, object $message): Behavior
            {
                $this->handled = true;

                return Behavior::same();
            }
        };

        self::assertInstanceOf(ActorHandler::class, $handler);
        self::assertFalse($handler->handled);
    }
}
