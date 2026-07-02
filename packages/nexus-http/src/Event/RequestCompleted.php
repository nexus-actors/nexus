<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Event;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class RequestCompleted
{
    public function __construct(
        public ServerRequestInterface $request,
        public ResponseInterface $response,
        public int $durationNanos,
    ) {}
}
