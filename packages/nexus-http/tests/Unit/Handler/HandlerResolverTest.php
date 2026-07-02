<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\ActorMode;
use Monadial\Nexus\Http\Actor\ActorRegistrationEntry;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Exception\PerRequestActorInConstructorException;
use Monadial\Nexus\Http\Exception\UnknownActorException;
use Monadial\Nexus\Http\Handler\Attribute\FromActor;
use Monadial\Nexus\Http\Handler\Attribute\FromService;
use Monadial\Nexus\Http\Handler\HandlerResolver;
use Monadial\Nexus\Http\Response\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

final class _InjectsWorkerLocalCtor
{
    public function __construct(#[FromActor('store')] public ActorRef $store) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}

final class _InjectsPerRequestInCtor
{
    public function __construct(#[FromActor('saga')] public ActorRef $saga) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}

/** A plain PSR-11 service used to verify #[FromService] resolution. */
final class _Logger
{
    /** @var list<string> */
    public array $messages = [];

    public function log(string $message): void
    {
        $this->messages[] = $message;
    }
}

/** Handler that takes a #[FromService] in its constructor (by id). */
final class _InjectsServiceById
{
    public function __construct(#[FromService('logger.audit')] public _Logger $log) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        $this->log->log('called');

        return Response::ok();
    }
}

/** Handler that takes a #[FromService] in its constructor (by type — id omitted). */
final class _InjectsServiceByType
{
    public function __construct(#[FromService] public _Logger $log) {}

    public function __invoke(ServerRequestInterface $r): ResponseInterface
    {
        $this->log->log('byType');

        return Response::ok();
    }
}

/** Handler with a named method (not __invoke). Used to verify 'Class::method' string form. */
final class _NamedMethodHandler
{
    public function show(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok();
    }
}

/** Handler implementing RequestHandlerInterface (handle() method). */
final class _PsrInterfaceHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $r): ResponseInterface
    {
        return Response::ok()->withHeader('X-Handler', 'psr');
    }
}

/** Minimal PSR-11 container that returns pre-registered services by string id and class name. */
final class _ArrayContainer implements ContainerInterface
{
    /** @param array<string, object> $services */
    public function __construct(private readonly array $services) {}

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new RuntimeException("Service '{$id}' not in container");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
}

#[CoversClass(HandlerResolver::class)]
final class HandlerResolverTest extends TestCase
{
    #[Test]
    public function constructor_per_request_injection_throws(): void
    {
        [, $resolver] = $this->buildResolver();

        $this->expectException(PerRequestActorInConstructorException::class);
        $resolver->resolve(_InjectsPerRequestInCtor::class);
    }

    #[Test]
    public function method_per_request_injection_marks_needs_request_scope(): void
    {
        [, $resolver] = $this->buildResolver();

        $resolved = $resolver->resolve(static fn(
            ServerRequestInterface $r,
            #[FromActor('saga')]
            ActorRef $saga,
        ): ResponseInterface => Response::ok());

        self::assertTrue($resolved->needsRequestScope);
    }

    #[Test]
    public function resolves_class_implementing_request_handler_interface(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $resolver = new HandlerResolver($table, null);

        $resolved = $resolver->resolve(_PsrInterfaceHandler::class);

        $scope = new PerRequestActorScope($system, [], 'r-1');
        $response = ($resolved->invoke)(new ServerRequest('GET', '/'), $scope, []);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame('psr', $response->getHeaderLine('X-Handler'));
    }

    #[Test]
    public function resolves_class_method_string_form(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $resolver = new HandlerResolver($table, null);

        $resolved = $resolver->resolve(_NamedMethodHandler::class . '::show');

        $scope = new PerRequestActorScope($system, [], 'r-1');
        $response = ($resolved->invoke)(new ServerRequest('GET', '/'), $scope, []);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function resolves_class_with_ctor_actor_injection(): void
    {
        [, $resolver] = $this->buildResolver();

        $resolved = $resolver->resolve(_InjectsWorkerLocalCtor::class);

        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');
        $response = ($resolved->invoke)(new ServerRequest('GET', '/'), $scope, []);

        self::assertInstanceOf(ResponseInterface::class, $response);
    }

    #[Test]
    public function resolves_class_with_from_service_by_id(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $logger = new _Logger();
        $container = new _ArrayContainer(['logger.audit' => $logger]);
        $resolver = new HandlerResolver($table, $container);

        $resolved = $resolver->resolve(_InjectsServiceById::class);

        $scope = new PerRequestActorScope($system, [], 'r-1');
        ($resolved->invoke)(new ServerRequest('GET', '/'), $scope, []);

        self::assertSame(['called'], $logger->messages);
    }

    #[Test]
    public function resolves_class_with_from_service_by_type(): void
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);
        $logger = new _Logger();
        $container = new _ArrayContainer([_Logger::class => $logger]);
        $resolver = new HandlerResolver($table, $container);

        $resolved = $resolver->resolve(_InjectsServiceByType::class);

        $scope = new PerRequestActorScope($system, [], 'r-1');
        ($resolved->invoke)(new ServerRequest('GET', '/'), $scope, []);

        self::assertSame(['byType'], $logger->messages);
    }

    #[Test]
    public function resolves_closure_with_server_request_param(): void
    {
        [, $resolver] = $this->buildResolver();
        $handler = static fn(ServerRequestInterface $r): ResponseInterface => Response::ok();

        $resolved = $resolver->resolve($handler);

        $system = ActorSystem::create('test', new TestRuntime());
        $scope = new PerRequestActorScope($system, [], 'r-1');
        $response = ($resolved->invoke)(new ServerRequest('GET', '/'), $scope, []);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($resolved->needsRequestScope);
    }

    #[Test]
    public function unknown_actor_in_attribute_throws(): void
    {
        [, $resolver] = $this->buildResolver();

        $this->expectException(UnknownActorException::class);
        $resolver->resolve(static fn(
            ServerRequestInterface $r,
            #[FromActor('does-not-exist')]
            ActorRef $what,
        ): ResponseInterface => Response::ok());
    }

    /**
     * @return array{0: ActorSystem, 1: HandlerResolver}
     */
    private function buildResolver(): array
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([
            new ActorRegistrationEntry('store', $this->noopProps(), ActorMode::WorkerLocal, null, null),
            new ActorRegistrationEntry('saga', $this->noopProps(), ActorMode::PerRequest, null, null),
        ], $system, null);

        return [$system, new HandlerResolver($table, null)];
    }

    private function noopProps(): Props
    {
        return Props::fromBehavior(Behavior::receive(static fn($ctx, $msg) => Behavior::same()));
    }
}
