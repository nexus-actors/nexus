<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Producer;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Messenger\Exception\UnsupportedOperationException;
use Monadial\Nexus\Messenger\Stamp\SourceActorPathStamp;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * ActorRef backed by a Symfony Messenger sender — the location-transparent
 * egress API. Actor code telling this ref is byte-identical to a local send;
 * the message leaves the process through the configured transport.
 *
 * ask() is not supported in v1: broker request/reply requires correlation
 * stamps and a reply transport.
 *
 * Example:
 * ```php
 * $ref = new MessengerActorRef($transport, 'orders-out');
 * $ref->tell(new OrderPlaced('A-42'));
 * ```
 *
 * @psalm-api
 *
 * @template T of object
 * @template-implements ActorRef<T>
 */
final readonly class MessengerActorRef implements ActorRef
{
    public function __construct(
        private SenderInterface $sender,
        private string $senderName,
        private ?ActorPath $sourcePath = null,
    ) {
    }

    #[Override]
    public function tell(object $message): void
    {
        $envelope = new Envelope($message);

        if ($this->sourcePath !== null) {
            $envelope = $envelope->with(new SourceActorPathStamp((string) $this->sourcePath));
        }

        $this->sender->send($envelope);
    }

    #[Override]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new UnsupportedOperationException(
            'ask() is not supported on MessengerActorRef; broker request/reply is deferred beyond v1.',
        );
    }

    #[Override]
    public function path(): ActorPath
    {
        return ActorPath::root()->child('messenger')->child($this->senderName);
    }

    #[Override]
    public function isAlive(): bool
    {
        return true;
    }
}
