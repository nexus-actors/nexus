<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent;

/**
 * `{"type":"forfeit"}` — no player id; the channel actor forfeits on
 * behalf of the authenticated connection.
 */
final readonly class ForfeitIntent implements ClientIntent {}
