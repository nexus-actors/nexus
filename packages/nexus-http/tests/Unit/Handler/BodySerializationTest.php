<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler;

use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Handler\HandlerResolver;
use Monadial\Nexus\Http\Response\Response;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Serialization\MessageSerializer;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final readonly class _CreateUserCommand
{
    public function __construct(public string $name, public int $age) {}
}

final readonly class _UserView
{
    public function __construct(public string $id, public string $name) {}
}

/**
 * Stub serializer. Each instance returns a pre-set object for deserialize and a
 * pre-set string for serialize so the test can assert exact wiring.
 */
final class _StubSerializer implements MessageSerializer
{
    public ?string $lastSerializedClass = null;

    public ?string $lastDeserializedData = null;

    public ?string $lastDeserializedType = null;

    public function __construct(
        private readonly ?object $deserializeReturns = null,
        private readonly string $serializeReturns = '',
    ) {}

    public function serialize(object $message): string
    {
        $this->lastSerializedClass = $message::class;

        return $this->serializeReturns;
    }

    public function deserialize(string $data, string $type): object
    {
        $this->lastDeserializedData = $data;
        $this->lastDeserializedType = $type;

        return $this->deserializeReturns ?? new _CreateUserCommand('', 0);
    }
}

#[CoversClass(HandlerResolver::class)]
final class BodySerializationTest extends TestCase
{
    #[Test]
    public function from_body_param_deserializes_request_body(): void
    {
        $expected = new _CreateUserCommand('Tomas', 33);
        $serializer = new _StubSerializer(deserializeReturns: $expected);
        $resolver = $this->buildResolver($serializer);

        $captured = null;
        $handler = static function (#[FromBody] _CreateUserCommand $cmd) use (&$captured): ResponseInterface {
            $captured = $cmd;

            return Response::ok();
        };

        $resolved = $resolver->resolve($handler);
        $request = new ServerRequest('POST', '/users', [], '{"name":"Tomas","age":33}');
        $scope = $this->scope();

        ($resolved->invoke)($request, $scope, []);

        self::assertSame($expected, $captured);
        self::assertSame('{"name":"Tomas","age":33}', $serializer->lastDeserializedData);
        self::assertSame(_CreateUserCommand::class, $serializer->lastDeserializedType);
    }

    #[Test]
    public function handler_returning_typed_object_is_serialized(): void
    {
        $serializer = new _StubSerializer(serializeReturns: '{"id":"u-1","name":"Tomas"}');
        $resolver = $this->buildResolver($serializer);

        $handler = static fn(): _UserView => new _UserView('u-1', 'Tomas');

        $resolved = $resolver->resolve($handler);
        $response = ($resolved->invoke)(new ServerRequest('GET', '/users/u-1'), $this->scope(), []);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"id":"u-1","name":"Tomas"}', (string) $response->getBody());
        self::assertSame(_UserView::class, $serializer->lastSerializedClass);
    }

    #[Test]
    public function future_handler_returning_typed_object_is_awaited_and_serialized(): void
    {
        $serializer = new _StubSerializer(serializeReturns: '{"id":"u-2","name":"Async"}');
        $resolver = $this->buildResolver($serializer);

        $handler = static fn(): Future => Future::resolved(new _UserView('u-2', 'Async'));

        $resolved = $resolver->resolve($handler);
        $response = ($resolved->invoke)(new ServerRequest('GET', '/users/u-2'), $this->scope(), []);

        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"id":"u-2","name":"Async"}', (string) $response->getBody());
        self::assertSame(_UserView::class, $serializer->lastSerializedClass);
    }

    #[Test]
    public function from_body_without_serializer_throws_at_compile(): void
    {
        $resolver = $this->buildResolver(serializer: null);

        $handler = static fn(#[FromBody] _CreateUserCommand $cmd): ResponseInterface => Response::ok();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('#[FromBody]');
        $resolver->resolve($handler);
    }

    #[Test]
    public function typed_return_without_serializer_throws_at_invoke(): void
    {
        $resolver = $this->buildResolver(serializer: null);

        $handler = static fn(): _UserView => new _UserView('u-3', 'NoSerializer');

        // resolve() succeeds — return type isn't validated at compile.
        $resolved = $resolver->resolve($handler);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no MessageSerializer is wired');
        ($resolved->invoke)(new ServerRequest('GET', '/users/u-3'), $this->scope(), []);
    }

    private function buildResolver(?MessageSerializer $serializer): HandlerResolver
    {
        $system = ActorSystem::create('test', new TestRuntime());
        $table = ResolvedActorTable::build([], $system, null);

        return new HandlerResolver($table, null, $serializer);
    }

    private function scope(): PerRequestActorScope
    {
        $system = ActorSystem::create('test', new TestRuntime());

        return new PerRequestActorScope($system, [], 'r-1');
    }
}
