<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Inbox\MessageInbox;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * @psalm-api
 *
 * In-package InMemoryCommandBus for smoke testing the full command/event
 * flow. Production-shipped helpers should follow the same constructor
 * shape: locator + inbox + stack + clock.
 */
final readonly class InMemoryCommandBus implements EnvelopedCommandBus
{
    public function __construct(
        private CommandHandlerLocator $locator,
        private MessageInbox $inbox,
        private MessageContextStack $stack,
        private ClockInterface $clock,
    ) {}

    #[Override]
    public function dispatchCommand(Command $command): void
    {
        $now = $this->clock->now();
        $messageId = MessageId::generate();

        $metadata = $this->stack->current()
            ->map(
                static fn(MessageContext $parent): MessageMetadata => $parent->metadata->forCausedMessage(
                    $messageId,
                    $now,
                ),
            )
            ->getOrCall(
                static fn(): MessageMetadata => new MessageMetadata(
                    id: $messageId,
                    occurredAt: $now,
                    causationId: Option::none(),
                    correlationId: Option::none(),
                    conversationId: Option::none(),
                    schemaVersion: 1,
                    traceParent: Option::none(),
                    traceState: Option::none(),
                    expiresAt: Option::none(),
                    vectorClock: Option::none(),
                ),
            );

        $this->dispatchEnveloped(new Envelope($command, $metadata));
    }

    /**
     * @param Envelope<Command> $envelope
     */
    #[Override]
    public function dispatchEnveloped(Envelope $envelope): void
    {
        $handler = $this->locator->locate($envelope->message);
        $handlerClass = $handler::class;
        $messageId = $envelope->metadata->id;

        if (! $this->inbox->tryReserve($handlerClass, $messageId)) {
            return;
        }

        try {
            $this->stack->within(
                new MessageContext($envelope->metadata),
                static function () use ($handler, $envelope): void {
                    $handler($envelope->message);
                },
            );
            $this->inbox->markProcessed($handlerClass, $messageId, Option::some($this->clock->now()));
        } catch (Throwable $e) {
            $this->inbox->release($handlerClass, $messageId);

            throw $e;
        }
    }
}
