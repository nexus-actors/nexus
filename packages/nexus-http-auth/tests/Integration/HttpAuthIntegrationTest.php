<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Auth\Tests\Integration;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\Auth\Attribute\FromPrincipal;
use Monadial\Nexus\Http\Auth\Attribute\RequiresAuth;
use Monadial\Nexus\Http\Auth\Attribute\RequiresScope;
use Monadial\Nexus\Http\Auth\Middleware\AuthenticationMiddleware;
use Monadial\Nexus\Http\Auth\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Http\Auth\Principal;
use Monadial\Nexus\Http\Auth\Principal\SimplePrincipal;
use Monadial\Nexus\Http\Auth\Tests\Support\InMemoryAuthenticator;
use Monadial\Nexus\Http\Response\JsonResponse;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Http\Toolkit\Test\HttpTestClient;
use Monadial\Nexus\Http\Ws\CompiledApplication;
use Monadial\Nexus\Http\Ws\HttpApplication;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

#[CoversNothing]
final class HttpAuthIntegrationTest extends TestCase
{
    #[Test]
    public function publicRouteAcceptsAnonymousRequest(): void
    {
        self::markTestSkipped('Re-enabled in T15 after paramResolver() extension API');

        /** @phpstan-ignore-next-line unreachable */
        HttpTestClient::for($this->buildApp())
            ->get('/health')
            ->assertOk();
    }

    #[Test]
    public function requiresAuthWithValidTokenYields200(): void
    {
        self::markTestSkipped('Re-enabled in T15 after paramResolver() extension API');

        /** @phpstan-ignore-next-line unreachable */
        HttpTestClient::for($this->buildApp())
            ->withBearerToken('k_alice')
            ->get('/me')
            ->assertOk()
            ->assertJsonPath('id', 'alice');
    }

    #[Test]
    public function requiresAuthWithoutTokenYields401(): void
    {
        self::markTestSkipped('Re-enabled in T15 after paramResolver() extension API');

        /** @phpstan-ignore-next-line unreachable */
        HttpTestClient::for($this->buildApp())
            ->get('/me')
            ->assertUnauthorized()
            ->assertHeaderExists('WWW-Authenticate');
    }

    #[Test]
    public function requiresScopeWithTokenMissingScopeYields403(): void
    {
        self::markTestSkipped('Re-enabled in T15 after paramResolver() extension API');

        /** @phpstan-ignore-next-line unreachable */
        HttpTestClient::for($this->buildApp())
            ->withBearerToken('k_alice')
            ->post('/orders', ['sku' => 'X'])
            ->assertStatus(403)
            ->assertJsonPath('missing.0', 'orders.write');
    }

    #[Test]
    public function requiresScopeWithTokenCarryingScopeYields201(): void
    {
        self::markTestSkipped('Re-enabled in T15 after paramResolver() extension API');

        /** @phpstan-ignore-next-line unreachable */
        HttpTestClient::for($this->buildApp())
            ->withBearerToken('k_bob')
            ->post('/orders', ['sku' => 'X'])
            ->assertCreated();
    }

    private function buildApp(): CompiledApplication
    {
        $system = ActorSystem::create('http-auth-test', new StepRuntime());

        $auth = new InMemoryAuthenticator([
            'k_alice' => new SimplePrincipal('alice', scopes: ['orders.read']),
            'k_bob' => new SimplePrincipal('bob', scopes: ['orders.read', 'orders.write']),
        ]);

        // AuthenticationMiddleware: global — stamps Principal on every request.
        // AuthorizationMiddleware: PER-ROUTE — added to routes that need enforcement,
        //                          because it must run AFTER RouterMiddleware stamps
        //                          _resolvedHandlerClass.
        $app = HttpApplication::create($system)
            ->middleware(new AuthenticationMiddleware($auth));

        $app->get('/health', static fn(): ResponseInterface => Response::ok());
        $app->get('/me', MeHandler::class)->middleware(AuthorizationMiddleware::class);
        $app->post('/orders', CreateOrderHandler::class)->middleware(AuthorizationMiddleware::class);

        return $app->compile();
    }
}

#[RequiresAuth]
final class MeHandler
{
    public function __invoke(ServerRequestInterface $req, #[FromPrincipal] Principal $principal): ResponseInterface
    {
        return JsonResponse::ok(['id' => $principal->id()]);
    }
}

#[RequiresScope('orders.read', 'orders.write')]
final class CreateOrderHandler
{
    public function __invoke(ServerRequestInterface $req, #[FromPrincipal] Principal $principal): ResponseInterface
    {
        return JsonResponse::created(['ownedBy' => $principal->id()]);
    }
}
