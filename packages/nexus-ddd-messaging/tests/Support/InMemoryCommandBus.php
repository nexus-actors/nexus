<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContext;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Identity\NodeId;
use Monadial\Nexus\Ddd\Messaging\Inbox\MessageInbox;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Override;
use Psr\Clock\ClockInterface;

/**
 * @psalm-api
 *
 * In-package InMemoryCommandBus for smoke testing the full command/event
 * flow. Production-shipped helpers should follow the same constructor
 * shape: locator + inbox + stack + clock + nodeId.
 */
final readonly class InMemoryCommandBus implements EnvelopedCommandBus
{
    public function __construct(
        private CommandHandlerLocator $locator,
        private MessageInbox $inbox,
        private MessageContextStack $stack,
        private ClockInterface $clock,
        private NodeId $nodeId,
    ) {}

    #[Override]
    public function dispatchCommand(Command $command): void
    {
        $now = $this->clock->now();
        $messageId = MessageId::generate();

        $metadata = $this->stack->current()
            ->map(fn(MessageContext $parent): MessageMetadata => $parent->metadata->forCausedMessage($messageId, $now, $this->nodeId))
            ->getOrCall(fn(): MessageMetadata => MessageMetadata::root($this->clock, $this->nodeId));

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

        if (! $this->inbox->tryReserve($handlerClass, $envelope->metadata->id)) {
            return;
        }

        $this->stack->within(
            new MessageContext($envelope->metadata),
            static function () use ($handler, $envelope): void {
                $handler($envelope->message);
            },
        );
    }
}
