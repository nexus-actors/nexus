<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Actor\Message;

use Monadial\Nexus\Example\TicTacToe\Domain\View\GameSnapshot;

/**
 * Actor-layer reply to a successful join. Carries the new authoritative
 * {@see GameSnapshot} (which the channel actor broadcasts to everyone) PLUS
 * the originating `fd` and the seat `token`, so the channel actor can send
 * that one connection a private welcome with its capability token and mark.
 */
final readonly class Seated
{
    public function __construct(public GameSnapshot $snapshot, public int $fd, public string $token) {}
}
