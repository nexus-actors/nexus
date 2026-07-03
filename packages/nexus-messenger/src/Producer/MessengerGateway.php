<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Producer;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Explicit egress service for code that wants to be deliberate that a message
 * leaves the actor system. Same underlying sender as MessengerActorRef —
 * choose this API when "this goes to a broker" should be visible at the call
 * site.
 *
 * @psalm-api
 */
final readonly class MessengerGateway
{
    public function __construct(private SenderInterface $sender)
    {
    }

    /**
     * @param list<StampInterface> $stamps
     */
    public function publish(object $message, array $stamps = []): void
    {
        $this->sender->send(new Envelope($message, $stamps));
    }
}
