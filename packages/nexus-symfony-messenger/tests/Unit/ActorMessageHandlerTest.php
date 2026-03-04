<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Messenger\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Symfony\Messenger\ActorMessageHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

readonly class OrderMessage {}

#[CoversClass(ActorMessageHandler::class)]
final class ActorMessageHandlerTest extends TestCase
{
    #[Test]
    public function dispatchTellsActorRef(): void
    {
        $ref     = $this->createMock(ActorRef::class);
        $message = new OrderMessage();

        $ref->expects($this->once())->method('tell')->with($message);

        $handler = new ActorMessageHandler($ref);
        $handler->dispatch($message);
    }
}
