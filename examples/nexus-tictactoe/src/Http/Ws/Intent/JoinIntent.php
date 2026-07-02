<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws\Intent;

use Monadial\Nexus\Example\TicTacToe\Domain\Exception\InvalidCommandException;

use function preg_replace;
use function strlen;
use function trim;

/**
 * `{"type":"join","name":"Alice","token":"01JX..."?}`.
 *
 * `token` is the reconnect capability: absent on a first join (the server
 * mints one), present when resuming a seat. The display name is trimmed
 * and stripped of control characters before it is ever persisted or
 * rebroadcast.
 */
final readonly class JoinIntent implements ClientIntent
{
    public string $name;

    public function __construct(string $name, public ?string $token = null)
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        if ($name === '' || strlen($name) > 40) {
            throw new InvalidCommandException('name must be 1-40 printable chars');
        }

        if ($token !== null && ($token === '' || strlen($token) > 64)) {
            throw new InvalidCommandException('token must be 1-64 chars');
        }

        $this->name = $name;
    }
}
