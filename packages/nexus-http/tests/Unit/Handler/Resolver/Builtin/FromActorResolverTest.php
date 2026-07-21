<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistrationEntry;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\InvalidFromActorParameterException;
use Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromActorResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionParameter;
use stdClass;

#[CoversClass(FromActorResolver::class)]
final class FromActorResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_parameter_has_no_from_actor_attribute(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(static function (string $x): void {});

        self::assertNull($resolver->compile($param, $this->bootCtx([])));
    }

    #[Test]
    public function compiles_metadata_for_known_singleton_actor(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] object $greeter): void {},
        );

        $metadata = $resolver->compile($param, $this->bootCtx([$this->workerLocalEntry('greeter')]));

        self::assertNotNull($metadata);
        self::assertSame('greeter', $metadata->name);
        self::assertSame('greeter', $metadata->payload['actorName']);
        self::assertFalse($metadata->needsScope);
    }

    #[Test]
    public function resolves_to_singleton_actor_ref(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] object $greeter): void {},
        );
        $system = ActorSystem::create('test', new TestRuntime());
        $services = new ResolverServices(
            ResolvedActorTable::build([$this->workerLocalEntry('greeter')], $system, null),
        );
        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'TestOwner', $services));
        self::assertNotNull($metadata);

        $invocationCtx = new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/'),
            [],
            new PerRequestActorScope($system, [], 'req-1'),
        );
        $resolved = $resolver->resolve($metadata, $invocationCtx);

        self::assertSame($services->actors->resolve('greeter'), $resolved);
    }

    #[Test]
    public function throws_unknown_actor_at_compile_time(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('missing')] object $x): void {},
        );

        $this->expectException(UnknownActorException::class);

        $resolver->compile($param, $this->bootCtx([]));
    }

    #[Test]
    public function throws_when_per_request_actor_appears_in_constructor_scope(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('session')] object $session): void {},
        );

        $this->expectException(PerRequestActorInConstructorException::class);

        $resolver->compile($param, $this->bootCtx([$this->perRequestEntry('session')]));
    }

    #[Test]
    public function marks_needs_scope_true_for_per_request_actor(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('session')] object $session): void {},
        );
        $system = ActorSystem::create('test', new TestRuntime());
        $services = new ResolverServices(
            ResolvedActorTable::build([$this->perRequestEntry('session')], $system, null),
        );

        $metadata = $resolver->compile(
            $param,
            new CompileContext(Scope::HttpRequest, 'TestOwner', $services),
        );

        self::assertNotNull($metadata);
        self::assertTrue($metadata->needsScope);
        self::assertSame('session', $metadata->payload['actorName']);
    }

    // ========================================================================
    // Parameter type is validated at compile time (DSL-009)
    // ========================================================================

    #[Test]
    public function compiles_actor_ref_typed_parameter(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] ActorRef $greeter): void {},
        );

        $metadata = $resolver->compile($param, $this->bootCtx([$this->workerLocalEntry('greeter')]));

        self::assertNotNull($metadata);
    }

    #[Test]
    public function compiles_nullable_actor_ref_parameter(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] ?ActorRef $greeter): void {},
        );

        $metadata = $resolver->compile($param, $this->bootCtx([$this->workerLocalEntry('greeter')]));

        self::assertNotNull($metadata);
    }

    #[Test]
    public function compiles_union_parameter_containing_actor_ref(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] ActorRef|string $greeter): void {},
        );

        $metadata = $resolver->compile($param, $this->bootCtx([$this->workerLocalEntry('greeter')]));

        self::assertNotNull($metadata);
    }

    #[Test]
    public function compiles_untyped_parameter(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] $greeter): void {},
        );

        $metadata = $resolver->compile($param, $this->bootCtx([$this->workerLocalEntry('greeter')]));

        self::assertNotNull($metadata);
    }

    #[Test]
    public function throws_for_scalar_parameter_at_compile_time(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] string $greeter): void {},
        );

        $this->expectException(InvalidFromActorParameterException::class);
        $this->expectExceptionMessage('$greeter');

        $resolver->compile($param, $this->bootCtx([$this->workerLocalEntry('greeter')]));
    }

    #[Test]
    public function throws_for_incompatible_object_parameter_at_compile_time(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] stdClass $greeter): void {},
        );

        $this->expectException(InvalidFromActorParameterException::class);
        $this->expectExceptionMessage(ActorRef::class);

        $resolver->compile($param, $this->bootCtx([$this->workerLocalEntry('greeter')]));
    }

    #[Test]
    public function throws_for_union_without_actor_ref_at_compile_time(): void
    {
        $resolver = new FromActorResolver();
        $param = $this->refOf(
            static function (#[FromActor('greeter')] int|string $greeter): void {},
        );

        $this->expectException(InvalidFromActorParameterException::class);

        $resolver->compile($param, $this->bootCtx([$this->workerLocalEntry('greeter')]));
    }

    /**
     * @param list<ActorRegistrationEntry> $entries
     */
    private function bootCtx(array $entries): CompileContext
    {
        $system = ActorSystem::create('test', new TestRuntime());

        return new CompileContext(
            Scope::HttpBoot,
            'TestOwner',
            new ResolverServices(ResolvedActorTable::build($entries, $system, null)),
        );
    }

    private function perRequestEntry(string $name): ActorRegistrationEntry
    {
        return new ActorRegistrationEntry(
            $name,
            Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
            ActorMode::PerRequest,
            null,
            null,
        );
    }

    private function refOf(callable $fn): ReflectionParameter
    {
        return (new ReflectionFunction($fn))->getParameters()[0];
    }

    private function workerLocalEntry(string $name): ActorRegistrationEntry
    {
        return new ActorRegistrationEntry(
            $name,
            Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same())),
            ActorMode::WorkerLocal,
            null,
            null,
        );
    }
}
