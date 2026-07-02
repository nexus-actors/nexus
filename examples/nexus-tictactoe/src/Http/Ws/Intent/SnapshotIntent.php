<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent;

/**
 * `{"type":"snapshot"}` — a read poll. Served from the channel actor's
 * cache when warm, otherwise a one-shot read of the aggregate.
 */
final readonly class SnapshotIntent implements ClientIntent {}
