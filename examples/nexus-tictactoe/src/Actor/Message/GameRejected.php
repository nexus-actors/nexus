<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Actor\Message;

/**
 * Actor-layer failure reply. Sent instead of a snapshot when a domain rule
 * fires. Carries the offending connection's `fd` so the channel actor
 * surfaces the error to that one client — NOT to every spectator.
 */
final readonly class GameRejected
{
    public function __construct(public string $reason, public int $fd) {}
}
