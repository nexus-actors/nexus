<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Doctrine\Orm\Exception\MissingEntityManagerScopeException;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerLease;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Tests\Support\StubEntityManagerFactory;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;

#[CoversClass(EntityManagerResolver::class)]
final class EntityManagerResolverTest extends TestCase
{
    #[Test]
    public function compileMatchesEmTypedParameter(): void
    {
        $resolver = new EntityManagerResolver();
        /** @psalm-suppress UnusedClosureParam */
        $reflection = new ReflectionFunction(static function (EntityManagerInterface $em): void {});
        $param = $reflection->getParameters()[0];

        $metadata = $resolver->compile($param, $this->compileContext());

        self::assertNotNull($metadata);
        self::assertSame('em', $metadata->name);
        self::assertSame(EntityManagerInterface::class, $metadata->type);
    }

    #[Test]
    public function compileSkipsNonEmParameter(): void
    {
        $resolver = new EntityManagerResolver();
        /** @psalm-suppress UnusedClosureParam */
        $reflection = new ReflectionFunction(static function (int $i): void {});
        $param = $reflection->getParameters()[0];

        $metadata = $resolver->compile($param, $this->compileContext());

        self::assertNull($metadata);
    }

    #[Test]
    public function resolveReturnsBorrowedEm(): void
    {
        $pool = $this->pool();
        $lease = new EntityManagerLease($pool);
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute(EntityManagerLease::class, $lease);

        $resolver = new EntityManagerResolver();
        /** @psalm-suppress UnusedClosureParam */
        $reflection = new ReflectionFunction(static function (EntityManagerInterface $em): void {});
        $metadata = $resolver->compile($reflection->getParameters()[0], $this->compileContext());

        self::assertNotNull($metadata);
        $value = $resolver->resolve($metadata, $this->requestContext($request));

        self::assertInstanceOf(EntityManagerInterface::class, $value);
        $lease->release();
    }

    #[Test]
    public function resolveThrowsWhenScopeMissing(): void
    {
        $request = new ServerRequest('GET', '/');
        $resolver = new EntityManagerResolver();
        /** @psalm-suppress UnusedClosureParam */
        $reflection = new ReflectionFunction(static function (EntityManagerInterface $em): void {});
        $metadata = $resolver->compile($reflection->getParameters()[0], $this->compileContext());

        self::assertNotNull($metadata);
        $this->expectException(MissingEntityManagerScopeException::class);
        $resolver->resolve($metadata, $this->requestContext($request));
    }

    private function compileContext(): CompileContext
    {
        return new CompileContext(Scope::HttpRequest, 'TestOwner', new ResolverServices());
    }

    private function requestContext(ServerRequestInterface $request): HttpRequestContext
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'req-1');

        return new HttpRequestContext(new ResolverServices(), $request, [], $scope);
    }

    /** @psalm-suppress ArgumentTypeCoercion */
    private function pool(): EntityManagerPool
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        $emFactory = new StubEntityManagerFactory();
        $emFactory->prepend($em);

        $connPool = new ConnectionPool(
            name: 'em-private',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        return new EntityManagerPool(
            name: 'orders',
            factory: $emFactory,
            connPool: $connPool,
            config: new EmPoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
    }
}
