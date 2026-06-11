<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\App;

use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @psalm-api
 *
 * Immutable, ready-to-serve HTTP app. Produced by HttpApp::compile().
 * Implements PSR-15 RequestHandlerInterface — server adapters consume this.
 *
 * The internal handler chain (exception mw → globals → router) is compiled
 * once during construction; handle() invokes it directly with no per-request
 * stack assembly.
 *
 * The PSR-14 event hookup for RequestStarted / RequestCompleted is added in
 * Phase 13 (this class keeps the events reference but doesn't dispatch yet
 * at this phase).
 */
final readonly class CompiledHttpApp implements RequestHandlerInterface
{
    public function __construct(
        private RequestHandlerInterface $compiledHandler,
        private ?EventDispatcherInterface $events,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->compiledHandler->handle($request);
    }
}
