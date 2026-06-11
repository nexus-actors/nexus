<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Event;

use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class RequestStarted
{
    public function __construct(public ServerRequestInterface $request, public int $startNanos) {}
}
