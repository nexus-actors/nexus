<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Platform\Http;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Example\Fulfillment\Platform\Http\VoParamResolver;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\GenericHttpException;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionParameter;

#[CoversClass(VoParamResolver::class)]
final class VoParamResolverTest extends TestCase
{
    #[Test]
    public function resolves_OrderId_from_path_param(): void
    {
        $resolver = new VoParamResolver();
        $param = $this->refOf(static function (OrderId $id): void {});
        $services = $this->services();

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'TestOwner', $services));

        self::assertNotNull($metadata);
        self::assertSame('id', $metadata->name);
        self::assertSame(OrderId::class, $metadata->type);

        $validUlid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $invocationCtx = new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/api/orders/' . $validUlid),
            ['id' => $validUlid],
            new PerRequestActorScope(ActorSystem::create('test', new FiberRuntime()), [], 'req-1'),
        );

        $result = $resolver->resolve($metadata, $invocationCtx);

        self::assertInstanceOf(OrderId::class, $result);
        self::assertSame(strtoupper($validUlid), $result->value);
    }

    #[Test]
    public function returns_null_compile_for_non_SharedKernel_class(): void
    {
        $resolver = new VoParamResolver();

        $stringParam = $this->refOf(static function (string $id): void {});
        self::assertNull($resolver->compile($stringParam, $this->requestCtx()));
    }

    #[Test]
    public function returns_null_in_boot_scope(): void
    {
        $resolver = new VoParamResolver();
        $param = $this->refOf(static function (OrderId $id): void {});

        self::assertNull($resolver->compile($param, new CompileContext(Scope::HttpBoot, 'TestOwner', $this->services())));
    }

    #[Test]
    public function throws_GenericHttpException_400_on_invalid_path_value(): void
    {
        $resolver = new VoParamResolver();
        $param = $this->refOf(static function (OrderId $id): void {});
        $services = $this->services();

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'TestOwner', $services));
        self::assertNotNull($metadata);

        $invocationCtx = new HttpRequestContext(
            $services,
            new ServerRequest('GET', '/api/orders/not-a-ulid'),
            ['id' => 'not-a-ulid'],
            new PerRequestActorScope(ActorSystem::create('test', new FiberRuntime()), [], 'req-1'),
        );

        $this->expectException(GenericHttpException::class);
        $this->expectExceptionMessage('Invalid order id:');

        $resolver->resolve($metadata, $invocationCtx);
    }

    #[Test]
    public function returns_null_compile_for_multi_arg_constructor_VO(): void
    {
        $resolver = new VoParamResolver();
        $param = $this->refOf(static function (Money $money): void {});

        self::assertNull($resolver->compile($param, $this->requestCtx()));
    }

    private function requestCtx(): CompileContext
    {
        return new CompileContext(Scope::HttpRequest, 'TestOwner', $this->services());
    }

    private function refOf(callable $fn): ReflectionParameter
    {
        return (new ReflectionFunction($fn))->getParameters()[0];
    }

    private function services(): ResolverServices
    {
        $system = ActorSystem::create('test', new FiberRuntime());

        return new ResolverServices(
            ResolvedActorTable::build([], $system, null),
        );
    }
}
