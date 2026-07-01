<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Actor\Message;

/**
 * Actor-layer failure reply. Sent instead of a snapshot when a domain
 * rule fires — carries the exception's short message so the channel actor
 * can surface it as an error frame without knowing the exception type.
 */
final readonly class GameRejected
{
    public function __construct(public string $reason) {}
}
