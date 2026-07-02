<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Handler\Resolver\Builtin;

use LogicException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Http\Actor\PerRequestActorScope;
use Monadial\Nexus\Http\Actor\ResolvedActorTable;
use Monadial\Nexus\Http\Handler\Attribute\FromBody;
use Monadial\Nexus\Http\Handler\Resolver\Builtin\FromBodyResolver;
use Monadial\Nexus\Http\Handler\Resolver\CompileContext;
use Monadial\Nexus\Http\Handler\Resolver\HttpRequestContext;
use Monadial\Nexus\Http\Handler\Resolver\ResolverServices;
use Monadial\Nexus\Http\Handler\Resolver\Scope;
use Monadial\Nexus\Serialization\MessageSerializer;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionParameter;

#[CoversClass(FromBodyResolver::class)]
final class FromBodyResolverTest extends TestCase
{
    #[Test]
    public function returns_null_when_parameter_has_no_from_body_attribute(): void
    {
        $resolver = new FromBodyResolver();
        $param = $this->refOf(static function (FromBodyFakeDto $x): void {});

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpRequest, null)));
    }

    #[Test]
    public function returns_null_in_boot_scope_even_with_attribute(): void
    {
        $resolver = new FromBodyResolver();
        $param = $this->refOf(
            static function (#[FromBody] FromBodyFakeDto $dto): void {},
        );

        self::assertNull($resolver->compile($param, $this->ctx(Scope::HttpBoot, $this->serializer())));
    }

    #[Test]
    public function throws_when_parameter_has_no_class_type_hint(): void
    {
        $resolver = new FromBodyResolver();
        $param = $this->refOf(
            static function (#[FromBody] $dto): void {},
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no class type hint');

        $resolver->compile($param, $this->ctx(Scope::HttpRequest, $this->serializer()));
    }

    #[Test]
    public function throws_when_no_message_serializer_wired(): void
    {
        $resolver = new FromBodyResolver();
        $param = $this->refOf(
            static function (#[FromBody] FromBodyFakeDto $dto): void {},
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no MessageSerializer is wired');

        $resolver->compile($param, $this->ctx(Scope::HttpRequest, null));
    }

    #[Test]
    public function resolves_via_message_serializer(): void
    {
        $resolver = new FromBodyResolver();
        $param = $this->refOf(
            static function (#[FromBody] FromBodyFakeDto $dto): void {},
        );
        $services = $this->services($this->serializer());

        $metadata = $resolver->compile($param, new CompileContext(Scope::HttpRequest, 'TestOwner', $services));
        self::assertNotNull($metadata);
        self::assertSame('dto', $metadata->name);
        self::assertSame(FromBodyFakeDto::class, $metadata->type);

        $body = (new Psr17Factory())->createStream('{"value":"hello"}');
        $request = (new ServerRequest('POST', '/'))->withBody($body);

        $invocationCtx = new HttpRequestContext(
            $services,
            $request,
            [],
            new PerRequestActorScope(ActorSystem::create('test', new TestRuntime()), [], 'req-1'),
        );

        $result = $resolver->resolve($metadata, $invocationCtx);

        self::assertInstanceOf(FromBodyFakeDto::class, $result);
        self::assertSame('hello', $result->value);
    }

    private function ctx(Scope $scope, ?MessageSerializer $serializer): CompileContext
    {
        return new CompileContext($scope, 'TestOwner', $this->services($serializer));
    }

    private function refOf(callable $fn): ReflectionParameter
    {
        return (new ReflectionFunction($fn))->getParameters()[0];
    }

    private function serializer(): MessageSerializer
    {
        return new class implements MessageSerializer {
            #[Override]
            public function deserialize(string $data, string $type): object
            {
                /** @var array{value: string} $decoded */
                $decoded = json_decode($data, true);

                return new FromBodyFakeDto($decoded['value']);
            }

            #[Override]
            public function serialize(object $message): string
            {
                /** @var FromBodyFakeDto $message */
                return json_encode(['value' => $message->value], JSON_THROW_ON_ERROR);
            }
        };
    }

    private function services(?MessageSerializer $serializer): ResolverServices
    {
        $system = ActorSystem::create('test', new TestRuntime());

        return new ResolverServices(
            ResolvedActorTable::build([], $system, null),
            null,
            $serializer,
        );
    }
}

final readonly class FromBodyFakeDto
{
    public function __construct(public string $value) {}
}
