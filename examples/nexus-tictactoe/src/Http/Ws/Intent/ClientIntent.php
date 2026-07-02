<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent;

/**
 * Marker for what a client asked for on the wire.
 *
 * Intents are deliberately NOT domain commands: a `move` intent carries
 * only the cell, never a player id. The channel actor decides identity
 * from the authenticated connection and builds the real
 * {@see \Monadial\Nexus\Example\TicTacToe\Domain\Command\GameCommand}.
 * This is what makes impersonation impossible — the client cannot assert
 * who it is on a gameplay frame.
 */
interface ClientIntent {}
