<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionResolver;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionScopeMiddleware;
use Monadial\Nexus\Doctrine\Dbal\Http\DoctrineHttp;
use Monadial\Nexus\Doctrine\Dbal\Http\PoolExhaustedToServiceUnavailable;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException;
use Monadial\Nexus\Http\Handler\Resolver\ParamResolverRegistry;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

#[CoversClass(DoctrineHttp::class)]
final class DoctrineHttpTest extends TestCase
{
    #[Test]
    public function installReturnsRegistryThatResolvesConnectionParam(): void
    {
        $pool = $this->makePool();
        $registry = new ParamResolverRegistry();
        $middlewares = [];

        $updated = DoctrineHttp::install(registry: $registry, middlewares: $middlewares, connPool: $pool);

        // Behavioral: compiling a Connection-typed parameter must succeed
        $param = (new ReflectionFunction(static function (Connection $c): void {}))->getParameters()[0];
        $ctx = new CompileContext(Scope::HttpRequest, 'TestOwner', new ResolverServices());

        $metadata = $updated->compile($param, $ctx);

        self::assertSame('c', $metadata->name);
        self::assertSame(Connection::class, $metadata->type);
        self::assertInstanceOf(ConnectionResolver::class, $metadata->resolver);
    }

    #[Test]
    public function installAppendsScopeAndExhaustedMiddlewares(): void
    {
        $pool = $this->makePool();
        $registry = new ParamResolverRegistry();
        $middlewares = [];

        DoctrineHttp::install(registry: $registry, middlewares: $middlewares, connPool: $pool);

        $hasScopeMiddleware = false;
        $hasExhaustedMiddleware = false;

        foreach ($middlewares as $m) {
            if ($m instanceof ConnectionScopeMiddleware) {
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
    public function installDoesNotMutateOriginalRegistry(): void
    {
        $pool = $this->makePool();
        $registry = new ParamResolverRegistry();
        $middlewares = [];

        DoctrineHttp::install(registry: $registry, middlewares: $middlewares, connPool: $pool);

        // The original registry must remain empty (immutable contract)
        $param = (new ReflectionFunction(static function (Connection $c): void {}))->getParameters()[0];
        $ctx = new CompileContext(Scope::HttpRequest, 'TestOwner', new ResolverServices());

        $this->expectException(UnresolvableParameterException::class);
        $registry->compile($param, $ctx);
    }

    private function makePool(): ConnectionPool
    {
        return new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
    }
}
