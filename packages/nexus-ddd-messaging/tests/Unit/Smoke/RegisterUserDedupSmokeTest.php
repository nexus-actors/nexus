<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Clock\VectorClock;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Inbox\InMemoryMessageInbox;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\InMemoryCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEventBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures\RegisterUser;
use Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures\RegisterUserHandler;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RegisterUserDedupSmokeTest extends TestCase
{
    #[Test]
    public function secondDispatchWithSameMessageIdIsDedupSkipped(): void
    {
        $events = new RecordingEventBus();
        $handler = new RegisterUserHandler($events);
        $locator = new class ($handler) implements CommandHandlerLocator {
            public function __construct(private readonly RegisterUserHandler $handler) {}

            #[Override]
            public function locate(Command $command): CommandHandler
            {
                return $this->handler;
            }
        };
        $clock = new SystemClock();
        $nodeId = NodeId::generate();
        $inbox = new InMemoryMessageInbox();
        $stack = MessageContextStack::default();
        $bus = new InMemoryCommandBus($locator, $inbox, $stack, $clock, $nodeId);

        $cmd = new RegisterUser('user-7', 'a@b.c');
        $messageId = MessageId::generate();
        $envelope = new Envelope(
            $cmd,
            new MessageMetadata(
                id: $messageId,
                occurredAt: $clock->now(),
                causationId: Option::none(),
                correlationId: Option::none(),
                conversationId: Option::none(),
                schemaVersion: 1,
                traceParent: Option::none(),
                traceState: Option::none(),
                expiresAt: Option::none(),
                vectorClock: VectorClock::empty()->tick($nodeId),
            ),
        );

        $bus->dispatchEnveloped($envelope);
        $bus->dispatchEnveloped($envelope);

        self::assertCount(1, $events->recorded());
    }
}
