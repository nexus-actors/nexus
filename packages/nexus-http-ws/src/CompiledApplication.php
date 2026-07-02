<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws;

use Psr\Http\Server\RequestHandlerInterface;

/** @psalm-api */
interface CompiledApplication extends RequestHandlerInterface
{
    public function hasWebSocketRoutes(): bool;
}
