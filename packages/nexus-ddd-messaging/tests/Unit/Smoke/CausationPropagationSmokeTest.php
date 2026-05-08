<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke;

use Closure;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Inbox\InMemoryMessageInbox;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\InMemoryCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\WithRootContext;
use Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures\RegisterUser;
use Monadial\Nexus\Ddd\Messaging\Tests\Unit\Smoke\Fixtures\UserRegistered;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CausationPropagationSmokeTest extends TestCase
{
    #[Test]
    public function eventCausationIdEqualsCommandMessageId(): void
    {
        $events = new RecordingEnvelopedEventBus();
        $clock = new SystemClock();
        $stack = MessageContextStack::default();
        $observedCommandId = null;
        $captureCommandId = static function (MessageId $id) use (&$observedCommandId): void {
            $observedCommandId = $id;
        };

        $handler = new class ($events, $clock, $stack, $captureCommandId) implements CommandHandler {
            public function __construct(
                private readonly EnvelopedEventBus $events,
                private readonly SystemClock $clock,
                private readonly MessageContextStack $stack,
                private readonly Closure $captureCommandId,
            ) {}

            public function __invoke(RegisterUser $cmd): void
            {
                $parent = $this->stack->current()->get();
                ($this->captureCommandId)($parent->metadata->id);
                $eventMeta = $parent->metadata->forCausedMessage(
                    MessageId::generate(),
                    $this->clock->now(),
                );
                $this->events->publishEnveloped(new Envelope(new UserRegistered($cmd->userId), $eventMeta));
            }
        };

        $locator = new class ($handler) implements CommandHandlerLocator {
            public function __construct(private readonly CommandHandler $handler) {}

            #[Override]
            public function locate(Command $command): CommandHandler
            {
                return $this->handler;
            }
        };

        $bus = new InMemoryCommandBus($locator, new InMemoryMessageInbox(), $stack, $clock);
        $cmd = new RegisterUser('user-9', 'a@b.c');
        $helper = new WithRootContext($stack, $clock);

        $helper->run(static function () use ($bus, $cmd): void {
            $bus->dispatchCommand($cmd);
        });

        self::assertCount(1, $events->recordedEnvelopes());
        $eventCausation = $events->recordedEnvelopes()[0]->metadata->causationId->get();
        self::assertInstanceOf(MessageId::class, $observedCommandId);
        self::assertTrue(
            $eventCausation->equals($observedCommandId),
            'event.causationId must equal the dispatched command MessageId',
        );
    }
}
