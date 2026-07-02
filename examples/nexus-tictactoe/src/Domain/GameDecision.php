<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain;

use Monadial\Nexus\Example\TicTacToe\Domain\Event\GameEvent;

use function array_values;

/**
 * The outcome of applying a command to a {@see \Monadial\Nexus\Example\TicTacToe\Domain\State\GameState}:
 * either a list of events to persist (accepted) or a token-free rejection
 * reason (a rule fired). Pure data — {@see GameRules} produces it, the game
 * actor turns it into an `Effect`.
 *
 * @psalm-immutable
 */
final readonly class GameDecision
{
    /**
     * @param list<GameEvent> $events
     */
    private function __construct(public array $events, public ?string $rejection) {}

    public static function accept(GameEvent ...$events): self
    {
        return new self(array_values($events), null);
    }

    public static function reject(string $reason): self
    {
        return new self([], $reason);
    }

    public function isRejected(): bool
    {
        return $this->rejection !== null;
    }
}
