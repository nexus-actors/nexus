<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\App;

use Monadial\Nexus\Http\Event\RequestCompleted;
use Monadial\Nexus\Http\Event\RequestStarted;
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
 * PSR-14 events RequestStarted and RequestCompleted are emitted around the
 * compiled handler when an EventDispatcher is wired. The null-check fast
 * path makes the cost when no dispatcher is supplied a single identity check.
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
        if ($this->events === null) {
            return $this->compiledHandler->handle($request);
        }

        $start = hrtime(true);
        $this->events->dispatch(new RequestStarted($request, $start));

        $response = $this->compiledHandler->handle($request);

        $this->events->dispatch(
            new RequestCompleted($request, $response, hrtime(true) - $start),
        );

        return $response;
    }
}
