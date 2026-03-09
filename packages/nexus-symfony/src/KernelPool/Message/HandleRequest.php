<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\KernelPool\Message;

use Symfony\Component\HttpFoundation\Request;

/**
 * Sent via ask() to the KernelPoolActor to dispatch an HTTP request.
 * The pool replies with KernelResponse once a kernel processes the request.
 */
readonly class HandleRequest
{
    public function __construct(public Request $request) {}
}
