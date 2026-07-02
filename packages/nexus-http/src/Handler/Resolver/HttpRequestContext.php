<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @psalm-api
 *
 * Resolution context active during HTTP handler __invoke. Carries the per-
 * request actor scope that #[FromActor] (per-request) and direct
 * PerRequestActorScope type-hints need.
 */
final readonly class HttpRequestContext extends RequestBoundContext
{
    /**
     * @param array<string, string> $pathParams
     */
    public function __construct(
        ResolverServices $services,
        ServerRequestInterface $request,
        array $pathParams,
        public PerRequestActorScope $perRequestScope,
    ) {
        parent::__construct(Scope::HttpRequest, $services, $request, $pathParams);
    }
}
