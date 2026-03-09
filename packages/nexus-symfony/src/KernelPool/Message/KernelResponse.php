<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\KernelPool\Message;

use Symfony\Component\HttpFoundation\Response;

/**
 * Sent by KernelActor back to the original ask() caller after handling a request.
 * Also used by KernelPoolActor to reply 503 when the pool is overloaded or a kernel crashes.
 */
readonly class KernelResponse
{
    public function __construct(public Response $response) {}
}
