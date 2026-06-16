<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Tests\Unit\Http;

use Doctrine\DBAL\Connection;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Doctrine\Dbal\Exception\MissingConnectionScopeException;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionLease;
use Monadial\Nexus\Doctrine\Dbal\Http\ConnectionResolver;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\FiberChannel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolConfig;
use Monadial\Nexus\Doctrine\Dbal\Tests\Support\StubConnectionFactory;
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

#[CoversClass(ConnectionResolver::class)]
final class ConnectionResolverTest extends TestCase
{
    #[Test]
    public function compileMatchesConnectionTypedParameter(): void
    {
        $resolver = new ConnectionResolver();
        $reflection = new ReflectionFunction(static function (Connection $c): void {});
        $param = $reflection->getParameters()[0];

        $metadata = $resolver->compile($param, $this->compileContext());

        self::assertNotNull($metadata);
        self::assertSame('c', $metadata->name);
        self::assertSame(Connection::class, $metadata->type);
    }

    #[Test]
    public function compileSkipsNonConnectionParameter(): void
    {
        $resolver = new ConnectionResolver();
        $reflection = new ReflectionFunction(static function (int $i): void {});
        $param = $reflection->getParameters()[0];

        $metadata = $resolver->compile($param, $this->compileContext());

        self::assertNull($metadata);
    }

    #[Test]
    public function resolveReturnsBorrowedConnection(): void
    {
        $pool = new ConnectionPool(
            name: 'orders',
            factory: new StubConnectionFactory(),
            config: new PoolConfig(max: 1, minIdle: 0),
            channel: new FiberChannel(1),
        );
        $lease = new ConnectionLease($pool);
        $request = (new ServerRequest('GET', '/'))
            ->withAttribute(ConnectionLease::class, $lease);

        $resolver = new ConnectionResolver();
        $reflection = new ReflectionFunction(static function (Connection $c): void {});
        $metadata = $resolver->compile($reflection->getParameters()[0], $this->compileContext());

        self::assertNotNull($metadata);
        $value = $resolver->resolve($metadata, $this->requestContext($request));

        self::assertInstanceOf(Connection::class, $value);
        $lease->release();
    }

    #[Test]
    public function resolveThrowsWhenScopeMissing(): void
    {
        $request = new ServerRequest('GET', '/');
        $resolver = new ConnectionResolver();
        $reflection = new ReflectionFunction(static function (Connection $c): void {});
        $metadata = $resolver->compile($reflection->getParameters()[0], $this->compileContext());

        self::assertNotNull($metadata);
        $this->expectException(MissingConnectionScopeException::class);
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
}
