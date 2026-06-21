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
 * Immutable, ready-to-serve PSR-15 request handler produced by {@see HttpApp::compile()}.
 *
 * `CompiledHttpApp` is the result of freezing an {@see HttpApp} DSL. It holds
 * the fully assembled middleware pipeline (exception handler → global middleware →
 * router) compiled once at boot time. The {@see handle()} method invokes that
 * chain directly with no per-request stack allocation.
 *
 * Server adapters (Swoole HTTP server, Fiber-based development server, etc.) receive
 * a `CompiledHttpApp` and call {@see handle()} for every incoming request. Application
 * code should never need to construct or call this class directly.
 *
 * PSR-14 lifecycle events are emitted when an `EventDispatcherInterface` is
 * wired during {@see HttpApp::create()}:
 * - {@see RequestStarted}   — emitted before the middleware chain is invoked.
 * - {@see RequestCompleted} — emitted after the response is produced, carrying elapsed nanoseconds.
 *
 * Example — handing the compiled app to a server adapter:
 * ```php
 * $compiled = HttpApp::create($system)->get('/ping', PingHandler::class)->compile();
 *
 * // Swoole HTTP server adapter:
 * $server->on('request', static function ($req, $res) use ($compiled): void {
 *     $response = $compiled->handle(PsrFactory::fromSwoole($req));
 *     // write $response back to $res …
 * });
 * ```
 *
 * @see HttpApp           The DSL that produces CompiledHttpApp via compile()
 * @see RequestStarted    PSR-14 event emitted at request start
 * @see RequestCompleted  PSR-14 event emitted after response is produced
 *
 * @psalm-api
 */
final readonly class CompiledHttpApp implements RequestHandlerInterface
{
    public function __construct(
        private RequestHandlerInterface $compiledHandler,
        private ?EventDispatcherInterface $events,
    ) {}

    /**
     * Dispatch the request through the compiled middleware pipeline.
     *
     * Emits {@see RequestStarted} and {@see RequestCompleted} PSR-14 events
     * when an `EventDispatcherInterface` was supplied at build time. When no
     * dispatcher is present the overhead is a single `null` identity check.
     *
     * @param ServerRequestInterface $request The incoming PSR-7 server request.
     * @return ResponseInterface              The produced HTTP response.
     */
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
