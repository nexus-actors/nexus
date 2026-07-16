<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\TicTacToe\Http\Ws;

/**
 * Every server-to-client WebSocket frame is `{ "type": ..., "data": {...} }`.
 * Keeping type + payload in one struct means the codec doesn't have to
 * flatten domain fields into a wire-shape sibling: `data` is whatever the
 * caller passes and the serializer renders it as-is.
 *
 * @psalm-api
 */
final readonly class WireEnvelope
{
    public function __construct(public string $type, public object $data) {}
}
