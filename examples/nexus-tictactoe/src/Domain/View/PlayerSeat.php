<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\View;

/**
 * A seated player. Pure read-model — the aggregate stores id/name as
 * four nullable columns because Doctrine 3 does not cleanly support
 * nullable embeddables (a partially-null embeddable still hydrates as a
 * non-null object).
 */
final readonly class PlayerSeat
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}
}
