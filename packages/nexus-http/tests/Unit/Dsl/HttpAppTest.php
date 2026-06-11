<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Dsl;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\App\CompiledHttpApp;
use Monadial\Nexus\Http\Dsl\HttpApp;
use Monadial\Nexus\Http\Dsl\RouteGroup;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
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
}

final class _HandlerWithUnknownActor
{
    public function __construct(#[FromActor('nonexistent')] public ActorRef $store) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}
