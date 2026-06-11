<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler;

use Closure;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Runtime\Async\Future;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Compiled handler. The hot path calls invoke($r, $scope, $pathParams) once.
 * Returns either ResponseInterface or Future<ResponseInterface>.
 */
final readonly class ResolvedHandler
{
    /**
     * @param Closure(ServerRequestInterface, PerRequestActorScope, array<string, string>): (ResponseInterface|Future) $invoke
     */
    public function __construct(public Closure $invoke, public bool $returnsResponse, public bool $needsRequestScope) {}
}
