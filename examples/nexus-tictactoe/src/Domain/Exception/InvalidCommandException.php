<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Domain\Exception;

/**
 * Thrown from a domain-command constructor when the value fails its
 * shape/length invariants. Kept separate from the aggregate-rule
 * exceptions so the codec can map it to a client-visible message
 * without persisting anything.
 */
final class InvalidCommandException extends GameDomainException {}
