<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Http\Handler;

use Monadial\Nexus\Example\Wallet\Http\Response\IndexResponse;
use Monadial\Nexus\Http\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * GET / — returns the link map for the demo and the smoke tests.
 *
 * The {@see IndexResponse} payload is built at boot time from
 * `HttpApplication::registeredRoutes()` — no hand-maintained list, no
 * drift when new routes are added.
 */
final readonly class IndexHandler
{
    public function __construct(private IndexResponse $index) {}

    public function __invoke(): ResponseInterface
    {
        return JsonResponse::ok($this->index);
    }
}
