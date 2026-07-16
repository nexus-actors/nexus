<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Http;

use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Doctrine\Orm\Http\DoctrineOrmHttp;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerResolver;
use Monadial\Nexus\Doctrine\Orm\Http\EntityManagerScopeMiddleware;
use Monadial\Nexus\Doctrine\Orm\Pool\EmPoolConfig;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerPool;
use Monadial\Nexus\Doctrine\Orm\Tests\Support\StubEntityManagerFactory;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(DoctrineOrmHttp::class)]
final class DoctrineOrmHttpTest extends TestCase
{
    #[Test]
    public function installOrmReturnsRegistryThatResolvesEntityManagerParam(): void
    {
        $pool = $this->makePool();
        $registry = new ParamResolverRegistry();
        $middlewares = [];

        $updated = DoctrineOrmHttp::installOrm(registry: $registry, middlewares: $middlewares, emPool: $pool);

        $param = (new ReflectionFunction(static function (EntityManagerInterface $em): void {}))->getParameters()[0];
        $ctx = new CompileContext(Scope::HttpRequest, 'TestOwner', new ResolverServices());

        $metadata = $updated->compile($param, $ctx);

        self::assertSame('em', $metadata->name);
        self::assertSame(EntityManagerInterface::class, $metadata->type);
        self::assertInstanceOf(EntityManagerResolver::class, $metadata->resolver);
    }

    #[Test]
    public function installOrmAppendsScopeAndExhaustedMiddlewares(): void
    {
        $pool = $this->makePool();
        $registry = new ParamResolverRegistry();
        $middlewares = [];

        DoctrineOrmHttp::installOrm(registry: $registry, middlewares: $middlewares, emPool: $pool);

        $hasScopeMiddleware = false;
        $hasExhaustedMiddleware = false;

        foreach ($middlewares as $m) {
            if ($m instanceof EntityManagerScopeMiddleware) {
                $hasScopeMiddleware = true;
            }

            if ($m instanceof PoolExhaustedToServiceUnavailable) {
                $hasExhaustedMiddleware = true;
            }
        }

        self::assertTrue($hasScopeMiddleware);
        self::assertTrue($hasExhaustedMiddleware);
    }

    #[Test]
    public function installOrmDoesNotMutateOriginalRegistry(): void
    {
        $pool = $this->makePool();
        $registry = new ParamResolverRegistry();
        $middlewares = [];

        DoctrineOrmHttp::installOrm(registry: $registry, middlewares: $middlewares, emPool: $pool);

        $param = (new ReflectionFunction(static function (EntityManagerInterface $em): void {}))->getParameters()[0];
        $ctx = new CompileContext(Scope::HttpRequest, 'TestOwner', new ResolverServices());

        $this->expectException(UnresolvableParameterException::class);
        $registry->compile($param, $ctx);
    }

    private function makePool(): EntityManagerPool
    {
        $connPool = new ConnectionPool(
            name: 'em-private',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );

        return new EntityManagerPool(
            name: 'orders',
            factory: new StubEntityManagerFactory(),
            connPool: $connPool,
            config: new EmPoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
    }
}
