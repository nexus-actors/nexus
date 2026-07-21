<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Dsl;

use Attribute;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PoolSingletonSpawner;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Http\Exception\GlobalAuthorizationMiddlewareException;
use Monadial\Nexus\Http\Exception\HttpAppAlreadyCompiledException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Exception\UnprotectedRouteException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Security\AuthorizationEnforcer;
use Monadial\Nexus\Http\Security\AuthorizationRequirement;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

#[CoversClass(HttpApp::class)]
#[CoversClass(CompiledHttpApp::class)]
final class HttpAppTest extends TestCase
{
    #[Test]
    public function compile_returns_compiled_http_app(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);

        $compiled = $app->compile();

        self::assertInstanceOf(CompiledHttpApp::class, $compiled);
    }

    #[Test]
    public function get_route_with_closure_handler_dispatches(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/hello', static fn(): ResponseInterface => Response::ok())
            ->name('hello');

        $response = $app->compile()->handle(new ServerRequest('GET', '/hello'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function group_prefix_and_middleware_apply(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->group('/api', static function (RouteGroup $g): void {
            $g->get('/ping', static fn(): ResponseInterface => Response::ok());
        });

        $response = $app->compile()->handle(new ServerRequest('GET', '/api/ping'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function requires_pool_singleton_reflects_registry(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);

        self::assertFalse($app->requiresPoolSingleton());
    }

    #[Test]
    public function unknown_route_returns_404(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/hello', static fn(): ResponseInterface => Response::ok());

        $response = $app->compile()->handle(new ServerRequest('GET', '/missing'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function compile_is_idempotent_across_multiple_calls(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/x', static fn(): ResponseInterface => Response::ok());

        $compiled1 = $app->compile();
        $compiled2 = $app->compile();

        self::assertSame(200, $compiled1->handle(new ServerRequest('GET', '/x'))->getStatusCode());
        self::assertSame(200, $compiled2->handle(new ServerRequest('GET', '/x'))->getStatusCode());
    }

    #[Test]
    public function failed_compile_does_not_persist_cache(): void
    {
        $cache = new Psr16Cache(new ArrayAdapter());

        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->withRouteCache($cache);
        $app->get('/x', _HandlerWithUnknownActor::class);

        try {
            $app->compile();
            self::fail('Expected compile to throw because of unknown actor reference');
        } catch (UnknownActorException) {
            // Expected — handler resolver throws on the unknown actor reference.
        }

        // Cache must be empty: a failed compile must not have written.
        self::assertNull($cache->get('nexus.http.routes'));
    }

    #[Test]
    public function group_middleware_runs_on_grouped_route(): void
    {
        _GroupSpyMiddleware::$hits = [];

        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->group('/api', static function (RouteGroup $g): void {
            $g->middleware(_GroupSpyMiddleware::class);
            $g->get('/ping', static fn(): ResponseInterface => Response::ok());
        });

        $response = $app->compile()->handle(new ServerRequest('GET', '/api/ping'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['/api/ping'], _GroupSpyMiddleware::$hits);
    }

    #[Test]
    public function on_exception_user_mapper_wins_over_default_throwable_fallback(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->onException(
            RuntimeException::class,
            static fn(): ResponseInterface => Response::badRequest(),
        );
        $app->get('/boom', static function (): ResponseInterface {
            throw new RuntimeException('boom');
        });

        $response = $app->compile()->handle(new ServerRequest('GET', '/boom'));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function requires_pool_singleton_is_true_when_a_pool_singleton_actor_is_registered(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
        $app->actor('store', $props)->poolSingleton();

        self::assertTrue($app->requiresPoolSingleton());
    }

    // ========================================================================
    // Compilation is terminal and idempotent (DSL-003, DSL-006)
    // ========================================================================

    #[Test]
    public function second_compile_reuses_worker_local_actors_instead_of_respawning(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
        $app->actor('store', $props);
        $app->get('/s', static fn(
            ServerRequestInterface $r,
            #[FromActor('store')]
            ActorRef $store,
        ): ResponseInterface => Response::ok());

        $compiled1 = $app->compile();
        $compiled2 = $app->compile();

        self::assertSame(200, $compiled1->handle(new ServerRequest('GET', '/s'))->getStatusCode());
        self::assertSame(200, $compiled2->handle(new ServerRequest('GET', '/s'))->getStatusCode());
    }

    #[Test]
    public function second_compile_spawns_pool_singletons_once(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
        $app->actor('store', $props)->poolSingleton();
        $spawner = new _CountingSpawner($system);
        $app->withPoolSingletonSpawner($spawner);

        $app->compile();
        $app->compile();

        self::assertSame(1, $spawner->spawns);
    }

    #[Test]
    public function repeated_compile_with_per_request_actor_succeeds(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
        $app->perRequestActor('saga', $props);
        $app->get('/s', static fn(
            ServerRequestInterface $r,
            #[FromActor('saga')]
            ActorRef $saga,
        ): ResponseInterface => Response::ok());

        $compiled1 = $app->compile();
        $compiled2 = $app->compile();

        self::assertSame(200, $compiled1->handle(new ServerRequest('GET', '/s'))->getStatusCode());
        self::assertSame(200, $compiled2->handle(new ServerRequest('GET', '/s'))->getStatusCode());
    }

    #[Test]
    public function route_registered_after_compile_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/x', static fn(): ResponseInterface => Response::ok());
        $app->compile();

        $this->expectException(HttpAppAlreadyCompiledException::class);
        $app->get('/late', static fn(): ResponseInterface => Response::ok());
    }

    #[Test]
    public function actor_registered_after_compile_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->compile();

        $props = Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));

        $this->expectException(HttpAppAlreadyCompiledException::class);
        $app->actor('late', $props);
    }

    #[Test]
    public function middleware_added_after_compile_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->compile();

        $this->expectException(HttpAppAlreadyCompiledException::class);
        $app->middleware(_GroupSpyMiddleware::class);
    }

    // ========================================================================
    // Annotated routes fail closed at compile time (SEC-003)
    // ========================================================================

    #[Test]
    public function annotated_route_without_enforcer_fails_compilation(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/protected', _ProtectedHandler::class);

        $this->expectException(UnprotectedRouteException::class);
        $this->expectExceptionMessage(_ProtectedHandler::class);
        $app->compile();
    }

    #[Test]
    public function annotated_route_with_route_enforcer_class_compiles(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/protected', _ProtectedHandler::class)
            ->middleware(_EnforcerMiddleware::class);

        $response = $app->compile()->handle(new ServerRequest('GET', '/protected'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function annotated_class_method_route_without_enforcer_fails_compilation(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/protected', _ProtectedHandler::class . '::__invoke');

        $this->expectException(UnprotectedRouteException::class);
        $app->compile();
    }

    #[Test]
    public function unannotated_route_without_enforcer_compiles(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->get('/open', static fn(): ResponseInterface => Response::ok());

        $response = $app->compile()->handle(new ServerRequest('GET', '/open'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function enforcer_in_global_middleware_fails_compilation(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->middleware(new _EnforcerMiddleware());
        $app->get('/open', static fn(): ResponseInterface => Response::ok());

        $this->expectException(GlobalAuthorizationMiddlewareException::class);
        $app->compile();
    }

    #[Test]
    public function group_registered_after_compile_throws(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $app = HttpApp::create($system);
        $app->compile();

        $this->expectException(HttpAppAlreadyCompiledException::class);
        $app->group('/api', static function (RouteGroup $g): void {
            $g->get('/late', static fn(): ResponseInterface => Response::ok());
        });
    }
}

final class _HandlerWithUnknownActor
{
    public function __construct(#[FromActor('nonexistent')] public ActorRef $store) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}

/** Test attribute implementing the authorization-requirement marker. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class _RequiresThing implements AuthorizationRequirement {}

/** Handler protected by the test requirement attribute. */
#[_RequiresThing]
final class _ProtectedHandler
{
    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}

/** Middleware implementing the authorization-enforcer marker. */
final class _EnforcerMiddleware implements MiddlewareInterface, AuthorizationEnforcer
{
    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

/** Pool-singleton spawner that counts spawns to verify compile() spawns exactly once. */
final class _CountingSpawner implements PoolSingletonSpawner
{
    public int $spawns = 0;

    public function __construct(private readonly ActorSystem $system) {}

    #[Override]
    public function spawn(Props $props, string $name): ActorRef
    {
        $this->spawns++;

        return $this->system->spawn($props, $name);
    }
}

final class _GroupSpyMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    public static array $hits = [];

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        self::$hits[] = $request->getUri()->getPath();

        return $handler->handle($request);
    }
}
