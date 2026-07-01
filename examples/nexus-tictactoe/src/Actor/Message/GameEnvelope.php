<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Actor\Message;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GameCommand;

/**
 * Actor-layer envelope pairing a domain {@see GameCommand} with the reply
 * target. Reply-target is transport, so it lives here — never in Domain.
 */
final readonly class GameEnvelope
{
    /**
     * @param ActorRef<object> $replyTo
     */
    public function __construct(public GameCommand $command, public ActorRef $replyTo) {}
}
