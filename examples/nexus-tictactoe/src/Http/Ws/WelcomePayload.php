<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

/**
 * Sent privately to a single connection right after a successful join:
 * `{"type":"welcome","data":{"mark":"X","token":"01JX..."}}`.
 *
 * `token` is the capability the client stores to reclaim this seat on
 * reconnect. `mark` is which side the connection controls (`null` for a
 * spectator). This frame is NEVER broadcast.
 */
final readonly class WelcomePayload
{
    public function __construct(public ?string $mark, public string $token) {}
}
