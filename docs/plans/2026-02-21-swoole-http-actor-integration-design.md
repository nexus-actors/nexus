# Swoole HTTP Server + Actor System Integration

## Summary

Integrate Swoole's HTTP server with the Nexus actor system, enabling HTTP requests to be handled by actors running across multiple worker processes. This is the foundation for future framework adapters (Symfony, Laravel).

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Request model | Request -> Actor message via `ask()` | HTTP is stateless glue; actors own all logic |
| Scaling model | Swoole HTTP Server workers + existing cluster | Each worker runs ActorSystem + ClusterNode; reuses ConsistentHashRing + UnixSocketTransport |
| Package structure | `nexus-http-api` (interfaces) + `nexus-http-swoole` (impl) | Clean separation; future framework adapters depend on `nexus-http-api` |
| Routing | PSR-15 middleware stack | Standard, composable, framework-compatible |
| PSR-7 impl | nyholm/psr7 | Lightest PSR-7 implementation, no bloat |
| Handler context | `HttpRequestContext` object | Rich context with `node()`, `system()`, `request()`, `param()`, `actorFor()`, `ask()` |
| Architecture | HttpClusterBootstrap (Swoole HTTP Server IS the process manager) | Natural Swoole architecture; replaces `Process\Pool` with `Http\Server` |

## Architecture Overview

```
Swoole\Http\Server (master process)
  +-- Worker 0: ActorSystem + ClusterNode + HttpKernel
  +-- Worker 1: ActorSystem + ClusterNode + HttpKernel
  +-- Worker 2: ActorSystem + ClusterNode + HttpKernel
  +-- Worker N: ActorSystem + ClusterNode + HttpKernel
        |
        +-- ConsistentHashRing routes actor messages
            across workers via UnixSocketTransport
```

Each HTTP worker is also an actor cluster node. Requests land on any worker and route to the correct actor (local or remote) transparently.

## Package Structure

### nexus-http-api (interfaces, no Swoole dependency)

```
packages/nexus-http-api/
  src/
    HttpKernel.php                    -- interface: handle PSR-7 request -> response
    HttpRequestContext.php            -- readonly: wraps request + ClusterNode + ActorSystem
    Routing/
      Route.php                       -- readonly VO: method, pattern, handler, middlewares
      RouteMatch.php                  -- readonly VO: matched route + extracted params
      Router.php                      -- interface: match(ServerRequest): ?RouteMatch
      SimpleRouter.php                -- basic impl (exact + {param} matching)
    Middleware/
      MiddlewareStack.php             -- PSR-15 dispatcher (chains middlewares -> handler)
    Handler/
      ActorHandler.php                -- PSR-15 handler: ask() an actor, return response
      CallableHandler.php             -- PSR-15 handler wrapping a closure
    Request/
      RequestConverter.php            -- interface: platform request -> PSR-7
      ResponseEmitter.php             -- interface: PSR-7 response -> platform response
  composer.json
```

**Dependencies:**
- `psr/http-message: ^2.0`
- `psr/http-server-handler: ^1.0`
- `psr/http-server-middleware: ^1.0`
- `monadial/nexus-core` (for ActorRef, Duration, ActorSystem)
- `monadial/nexus-cluster` (for ClusterNode)

### nexus-http-swoole (Swoole implementation)

```
packages/nexus-http-swoole/
  src/
    HttpClusterBootstrap.php          -- builder: configures Http\Server + cluster
    HttpClusterConfig.php             -- readonly VO: host, port, workerCount, swooleOptions
    SwooleRequestConverter.php        -- Swoole\Http\Request -> PSR-7 ServerRequest
    SwooleResponseEmitter.php         -- PSR-7 Response -> Swoole\Http\Response
    SwooleHttpKernel.php              -- implements HttpKernel: wires router + middleware
  composer.json
```

**Dependencies:**
- `monadial/nexus-http-api`
- `monadial/nexus-core`
- `monadial/nexus-cluster`
- `monadial/nexus-cluster-swoole`
- `monadial/nexus-runtime-swoole`
- `nyholm/psr7: ^1.8`

## User-Facing API

### Bootstrap

```php
HttpClusterBootstrap::create(
    HttpClusterConfig::create('0.0.0.0', 8080, workers: 4)
)
    ->onWorkerStart(function (ClusterNode $node, ActorSystem $system): void {
        $node->spawn(Props::fromBehavior($orderBehavior), 'orders');
        $node->spawn(Props::fromBehavior($paymentBehavior), 'payments');
    })
    ->onWorkerStop(function (ClusterNode $node, ActorSystem $system): void {
        // cleanup
    })
    ->middleware(new LoggingMiddleware())
    ->middleware(new AuthMiddleware())
    ->route('GET', '/orders/{id}', function (HttpRequestContext $ctx): ResponseInterface {
        $ref = $ctx->actorFor('/orders');
        $result = $ctx->ask(
            $ref,
            fn(ActorRef $replyTo) => new GetOrder($ctx->param('id'), $replyTo),
            Duration::seconds(5),
        );
        return new JsonResponse($result);
    })
    ->route('POST', '/orders', function (HttpRequestContext $ctx): ResponseInterface {
        $body = json_decode((string) $ctx->request()->getBody(), true);
        $ref = $ctx->actorFor('/orders');
        $result = $ctx->ask(
            $ref,
            fn(ActorRef $replyTo) => new CreateOrder($body, $replyTo),
            Duration::seconds(5),
        );
        return new JsonResponse($result, 201);
    })
    ->run();
```

### HttpRequestContext

```php
readonly class HttpRequestContext {
    public function request(): ServerRequestInterface;
    public function node(): ClusterNode;
    public function system(): ActorSystem;
    public function param(string $name): ?string;           // route parameter
    public function query(string $name): ?string;           // query string parameter
    public function actorFor(string $path): ?ActorRef;      // shortcut: node()->actorFor()
    public function ask(
        ActorRef $ref,
        callable $messageFactory,                           // fn(ActorRef $replyTo): object
        Duration $timeout,
    ): object;                                              // shortcut: ref->ask()
}
```

### PSR-15 Middleware Access

```php
class AuthMiddleware implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $ctx = $request->getAttribute(HttpRequestContext::class);
        $ctx->node();   // ClusterNode
        $ctx->system(); // ActorSystem
        return $handler->handle($request);
    }
}
```

### ActorHandler (convenience)

```php
->route('GET', '/orders/{id}', new ActorHandler(
    actor: 'orders',
    message: fn(HttpRequestContext $ctx) => new GetOrder($ctx->param('id')),
    response: fn(object $result) => new JsonResponse($result),
    timeout: Duration::seconds(5),
))
```

## Request Flow

```
Swoole\Http\Server onRequest($swooleReq, $swooleRes)
  |
  +-- SwooleRequestConverter::toServerRequest($swooleReq)
  |     -> Nyholm\Psr7\ServerRequest (PSR-7)
  |
  +-- Create HttpRequestContext($request, $clusterNode, $actorSystem)
  |
  +-- $request = $request->withAttribute(HttpRequestContext::class, $ctx)
  |
  +-- Router::match($request)
  |     -> RouteMatch { route, params: ['id' => '42'] }
  |     -> 404 Response if no match
  |
  +-- Inject route params into context
  |
  +-- MiddlewareStack::handle($request)
  |     -> Middleware 1 -> Middleware 2 -> ... -> Route Handler
  |     -> Route handler receives HttpRequestContext
  |     -> Handler calls $ctx->ask() -> actorRef->ask()
  |     -> Actor processes message in its coroutine, replies
  |     -> Handler builds PSR-7 Response
  |
  +-- SwooleResponseEmitter::emit($psr7Response, $swooleRes)
  |     -> Sets status, headers, body on Swoole response
  |
  +-- Done (coroutine freed for next request)
```

## HttpClusterBootstrap Lifecycle

`run()` sequence:

1. Create `SwooleTableDirectory::createTable()` in master process (shared memory)
2. Create `Swoole\Http\Server($host, $port)` with config options
3. Register `onWorkerStart`:
   - Create SwooleRuntime, ActorSystem, ConsistentHashRing
   - Create UnixSocketTransport, bind, connect to peers
   - Create ClusterNode, start it
   - Create SwooleHttpKernel (router + middleware stack)
   - Call user's `onWorkerStart($node, $system)` callback
   - Start actor event loop as coroutine (coexists with HTTP handling)
4. Register `onRequest`:
   - Convert Swoole request -> PSR-7
   - Create HttpRequestContext
   - Route + dispatch through middleware stack
   - Emit PSR-7 response -> Swoole response
5. Register `onWorkerStop`:
   - Call user's `onWorkerStop($node, $system)` callback
   - Graceful shutdown of ActorSystem + transport
6. `$server->start()` (blocks)

## Error Handling

| Error | HTTP Response |
|---|---|
| Route not found | 404 Not Found |
| Actor ask() timeout | 504 Gateway Timeout |
| Unhandled exception in handler | 500 Internal Server Error |
| Mailbox closed / actor stopped | 503 Service Unavailable |

Middleware can intercept and transform these (e.g., JSON error responses).

## Routing

SimpleRouter in `nexus-http-api`:

- `{param}` placeholders mapped to regex `[^/]+`
- Methods: GET, POST, PUT, DELETE, PATCH
- Linear scan matching (sufficient for typical route counts)
- `RouteMatch` carries extracted params -> injected into `HttpRequestContext`

## Dependency Graph

```
nexus-http-api (no Swoole dependency)
  +-- psr/http-message ^2.0
  +-- psr/http-server-handler ^1.0
  +-- psr/http-server-middleware ^1.0
  +-- monadial/nexus-core
  +-- monadial/nexus-cluster

nexus-http-swoole
  +-- monadial/nexus-http-api
  +-- monadial/nexus-core
  +-- monadial/nexus-cluster
  +-- monadial/nexus-cluster-swoole
  +-- monadial/nexus-runtime-swoole
  +-- nyholm/psr7 ^1.8
```

Deptrac: `nexus-http-api` must not depend on Swoole or cluster-swoole packages.

## Future Framework Adapters

This design enables:

- `nexus-http-symfony`: Symfony HttpKernel adapter using `nexus-http-api` interfaces. Converts PSR-7 to Symfony HttpFoundation, runs Symfony kernel, converts back.
- `nexus-http-laravel`: Laravel adapter using `nexus-http-api` interfaces. Runs Laravel's request pipeline inside Swoole workers.

Both would depend on `nexus-http-api` + `nexus-http-swoole` and add their framework-specific wiring.
