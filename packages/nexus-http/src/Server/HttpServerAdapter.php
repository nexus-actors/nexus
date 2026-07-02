<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Server;

use Monadial\Nexus\Runtime\Duration;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Server adapter contract. Implemented by concrete server packages
 * (nexus-http-server-swoole, hypothetical nexus-http-server-fiber, ...).
 *
 * Implementations MUST:
 * - Build PSR-7 ServerRequests from incoming HTTP requests
 * - Call $app->handle($request) for each request
 * - For ResponseInterface bodies that are streaming (read() returns chunks),
 *   call body->read() in a loop and flush per chunk to the wire — do NOT
 *   buffer the full body for streaming responses
 */
interface HttpServerAdapter
{
    /** Block and serve until shutdown is called. */
    public function serve(RequestHandlerInterface $app): void;

    /** Drain in-flight requests within the timeout, then stop. */
    public function shutdown(Duration $timeout): void;
}
