<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DefaultRequestCtx::class)]
final class DefaultRequestCtxTest extends TestCase
{
    #[Test]
    public function it_returns_path_param_from_attribute(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/orders/42');

        $ctx = new DefaultRequestCtx(
            request: $request,
            params: ['id' => '42'],
            system: ActorSystem::create('test', new StepRuntime()),
            registry: new MarshallerRegistry(),
            logger: new NullLogger(),
        );

        self::assertSame('42', $ctx->param('id'));
        self::assertNull($ctx->param('missing'));
    }

    #[Test]
    public function with_param_returns_a_new_ctx_with_added_param(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');

        $ctx = new DefaultRequestCtx(
            request: $request,
            params: [],
            system: ActorSystem::create('test', new StepRuntime()),
            registry: new MarshallerRegistry(),
            logger: new NullLogger(),
        );
        $next = $ctx->withParam('id', '7');

        self::assertNull($ctx->param('id'));
        self::assertSame('7', $next->param('id'));
    }

    #[Test]
    public function actor_for_returns_a_spawned_actor(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $system = ActorSystem::create('test', new StepRuntime());

        $spawned = $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn(ActorContext $ctx, object $msg) => Behavior::same(),
            )),
            'echo',
        );

        $ctx = new DefaultRequestCtx(
            request: $request,
            params: [],
            system: $system,
            registry: new MarshallerRegistry(),
            logger: new NullLogger(),
        );

        self::assertSame($spawned, $ctx->actorFor('echo'));
        self::assertSame($spawned, $ctx->actorFor('/echo'));
        self::assertNull($ctx->actorFor('missing'));
    }

    #[Override]
    protected function setUp(): void
    {
        if (!class_exists(MarshallerRegistry::class)) {
            self::markTestSkipped('MarshallerRegistry arrives in Task 9.');
        }
    }
}
