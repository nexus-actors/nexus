<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Actor\Message;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Example\TicTacToe\Domain\Command\GameCommand;
use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;

/**
 * Actor-layer envelope: a domain {@see GameCommand}, the actor to reply
 * to, and the WebSocket fd that originated the command.
 *
 * The reply-target and origin fd are transport concerns, so they live here
 * — never on the domain command. `originFd` lets the game actor address a
 * private reply (welcome, rejection) back to the one connection that acted,
 * instead of the channel broadcasting to everyone.
 */
final readonly class GameEnvelope
{
    /**
     * @param ActorRef<GameRejected|Seated|GameSnapshot> $replyTo
     */
    public function __construct(public GameCommand $command, public ActorRef $replyTo, public int $originFd) {}
}
