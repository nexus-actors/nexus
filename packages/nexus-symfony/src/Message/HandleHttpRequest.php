<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Message;

use Swoole\Coroutine\Channel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Message dispatched by NexusRunner to WorkerSupervisorActor for each HTTP request.
 *
 * The responseChannel is a Swoole coroutine Channel(1) created in the same
 * worker's coroutine context. WorkerSupervisorActor spawns a RequestActor that
 * pushes the Symfony Response (or false on timeout) to responseChannel when done.
 *
 * The onRequest handler pops from responseChannel (blocking the request coroutine)
 * and sends the result back via Swoole\Http\Response.
 */
final readonly class HandleHttpRequest
{
    public function __construct(
        public Request $request,
        public Channel $responseChannel,
    ) {}
}
