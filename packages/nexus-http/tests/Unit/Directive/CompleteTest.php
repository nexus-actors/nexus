<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Rejection\RouteRejection;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Step\StepFutureSlot;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use stdClass;

use function Monadial\Nexus\Http\complete;
use function Monadial\Nexus\Http\completeBuilt;
use function Monadial\Nexus\Http\completeWith;
use function Monadial\Nexus\Http\redirect;
use function Monadial\Nexus\Http\reject;

final class CompleteTest extends TestCase
{
    #[Test]
    public function complete_value_marshals_to_json_body(): void
    {
        $route = complete(['hello' => 'world']);
        $response = ($route->run)($this->ctx());

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"hello":"world"}', (string) $response->getBody());
    }

    #[Test]
    public function complete_with_status(): void
    {
        $route = complete(['ok' => true], 201);
        $response = ($route->run)($this->ctx());

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function complete_callable_value_is_invoked_with_ctx(): void
    {
        $route = complete(static fn($ctx) => ['method' => $ctx->request()->getMethod()]);
        $response = ($route->run)($this->ctx());

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('{"method":"GET"}', (string) $response->getBody());
    }

    #[Test]
    public function complete_with_returns_explicit_response(): void
    {
        $route = completeWith(new Response(204));
        $response = ($route->run)($this->ctx());

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function complete_built_invokes_builder(): void
    {
        $route = completeBuilt(static fn($ctx) => new Response(202));
        $response = ($route->run)($this->ctx());

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(202, $response->getStatusCode());
    }

    #[Test]
    public function redirect_sets_location_and_default_302(): void
    {
        $route = redirect('/elsewhere');
        $response = ($route->run)($this->ctx());

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/elsewhere', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function reject_throws(): void
    {
        $this->expectException(RouteRejection::class);
        $route = reject(new RouteRejection('forbidden', 'no', 403));
        ($route->run)($this->ctx());
    }

    #[Test]
    public function complete_awaits_future_values(): void
    {
        $slot = new StepFutureSlot();
        $payload = new stdClass();
        $payload->ok = true;
        $slot->resolve($payload);
        /** @var Future<object> $future */
        $future = new Future($slot);

        $route = complete($future);
        $response = ($route->run)($this->ctx());

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', (string) $response->getBody());
    }

    private function ctx(string $accept = 'application/json'): DefaultRequestCtx
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/')
            ->withHeader('Accept', $accept);

        return new DefaultRequestCtx(
            request: $request,
            params: [],
            system: ActorSystem::create('complete-test', new StepRuntime()),
            registry: MarshallerRegistry::withDefaults(),
            logger: new NullLogger(),
        );
    }
}
