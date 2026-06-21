---
title: CompiledHttpApp
sidebar_position: 19
related:
  - http/servers
  - reference/classes/http-app
---

# CompiledHttpApp

Immutable, ready-to-serve PSR-15 request handler produced by `HttpApp::compile()`.

## What it does

`CompiledHttpApp` is the output of freezing an `HttpApp` DSL. It holds the fully
assembled middleware pipeline — exception handler, global middleware, and router —
compiled once at boot time. The `handle()` method dispatches that pipeline directly
with no per-request stack allocation, keeping the hot path as thin as possible.

Server adapters (Swoole HTTP server, Fiber-based development server, etc.) receive a
`CompiledHttpApp` instance and call `handle(ServerRequestInterface $request)` for
every incoming request. Application code never constructs this class directly; it is
always obtained via `HttpApp::compile()`.

When an `EventDispatcherInterface` was supplied to `HttpApp::create()`, `CompiledHttpApp`
emits two PSR-14 lifecycle events per request:

- `RequestStarted` — dispatched before the middleware chain is invoked.
- `RequestCompleted` — dispatched after the response is produced, carrying elapsed
  nanoseconds (measured with `hrtime(true)`).

When no dispatcher is configured the overhead is a single `null` identity check —
no allocation, no branching cost.

## Example

```php title="src/Http/server.php"
use Monadial\Nexus\Http\Dsl\HttpApp;
use App\Handler\PingHandler;
use App\Handler\UserHandler;
use App\Middleware\AuthenticationMiddleware;

// Build and compile once at startup
$compiled = HttpApp::create($system, container: $container)
    ->get('/ping', PingHandler::class)
    ->get('/users/{id}', UserHandler::class)
    ->middleware(AuthenticationMiddleware::class)
    ->compile();    // <-- produces a CompiledHttpApp

// Swoole HTTP server adapter
$server->on('request', static function ($swooleReq, $swooleRes) use ($compiled): void {
    $psrRequest = PsrFactory::fromSwoole($swooleReq);
    $psrResponse = $compiled->handle($psrRequest);
    // write $psrResponse headers and body back to $swooleRes …
});

$server->start();
```

## Key methods

- `handle(ServerRequestInterface $request): ResponseInterface` — dispatches the
  request through the compiled pipeline. Implements
  `Psr\Http\Server\RequestHandlerInterface`. Emits `RequestStarted` and
  `RequestCompleted` PSR-14 events when a dispatcher is wired.

## Immutability guarantee

`CompiledHttpApp` is declared `final readonly`. Once created it never changes: the
middleware pipeline, event dispatcher, and route table are fixed. This means the same
`CompiledHttpApp` instance can safely be shared across coroutines or fibers without
locking.

## Full API reference

[Full class hierarchy and method list](https://api.nexusactors.com/classes/Monadial-Nexus-Http-App-CompiledHttpApp.html)

## See also

- [HTTP servers](../../http/servers) — Swoole and Fiber server adapter setup
- [HttpApp](http-app) — the DSL that produces `CompiledHttpApp` via `compile()`
