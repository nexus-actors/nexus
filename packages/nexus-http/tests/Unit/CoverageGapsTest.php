<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit;

use CuyZ\Valinor\MapperBuilder;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Extract\LongNumber;
use Monadial\Nexus\Http\Extract\UuidSegment;
use Monadial\Nexus\Http\Marshalling\JsonValinorMarshaller;
use Monadial\Nexus\Http\Marshalling\Marshaller;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Http\Marshalling\MediaType;
use Monadial\Nexus\Http\Middleware\BearerTokenMiddleware;
use Monadial\Nexus\Http\Middleware\LoggingMiddleware;
use Monadial\Nexus\Http\Rejection\BodyParseException;
use Monadial\Nexus\Http\Rejection\ExtractorRejection;
use Monadial\Nexus\Http\Routing\PathState;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Stringable;

#[CoversClass(DefaultRequestCtx::class)]
#[CoversClass(LongNumber::class)]
#[CoversClass(UuidSegment::class)]
#[CoversClass(JsonValinorMarshaller::class)]
#[CoversClass(MarshallerRegistry::class)]
#[CoversClass(BearerTokenMiddleware::class)]
#[CoversClass(LoggingMiddleware::class)]
#[CoversClass(BodyParseException::class)]
#[CoversClass(PathState::class)]
final class CoverageGapsTest extends TestCase
{
    #[Test]
    public function default_request_ctx_exposes_system_and_logger(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $system = ActorSystem::create('test', new StepRuntime());
        $logger = new NullLogger();

        $ctx = new DefaultRequestCtx(
            request: $request,
            params: [],
            system: $system,
            registry: new MarshallerRegistry(),
            logger: $logger,
        );

        self::assertSame($system, $ctx->system());
        self::assertSame($logger, $ctx->log());
    }

    #[Test]
    public function ask_throws_when_no_actor_at_path(): void
    {
        $factory = new Psr17Factory();
        $system = ActorSystem::create('test', new StepRuntime());

        $ctx = new DefaultRequestCtx(
            request: $factory->createServerRequest('GET', '/'),
            params: [],
            system: $system,
            registry: new MarshallerRegistry(),
            logger: new NullLogger(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("no actor at path 'missing'");
        $ctx->ask('missing', new stdClass());
    }

    #[Test]
    public function ask_future_throws_when_no_actor_at_path(): void
    {
        $factory = new Psr17Factory();
        $system = ActorSystem::create('test', new StepRuntime());

        $ctx = new DefaultRequestCtx(
            request: $factory->createServerRequest('GET', '/'),
            params: [],
            system: $system,
            registry: new MarshallerRegistry(),
            logger: new NullLogger(),
        );

        $this->expectException(RuntimeException::class);
        $ctx->askFuture('nope', new stdClass());
    }

    #[Test]
    public function ask_future_returns_future_for_existing_actor(): void
    {
        $factory = new Psr17Factory();
        $system = ActorSystem::create('test', new StepRuntime());

        $system->spawn(
            Props::fromBehavior(Behavior::receive(
                static fn(ActorContext $ctx, object $msg) => Behavior::same(),
            )),
            'echo',
        );

        $ctx = new DefaultRequestCtx(
            request: $factory->createServerRequest('GET', '/'),
            params: [],
            system: $system,
            registry: new MarshallerRegistry(),
            logger: new NullLogger(),
        );

        $future = $ctx->askFuture('echo', new stdClass());
        self::assertInstanceOf(Future::class, $future);
    }

    #[Test]
    public function long_number_parses_integer(): void
    {
        $extractor = new LongNumber();
        self::assertSame(123, $extractor->fromSegment('123'));
        self::assertSame(-456, $extractor->fromSegment('-456'));
    }

    #[Test]
    public function long_number_rejects_non_integer(): void
    {
        $extractor = new LongNumber();
        $this->expectException(ExtractorRejection::class);
        $extractor->fromSegment('abc');
    }

    #[Test]
    public function uuid_segment_parses_uuid(): void
    {
        $extractor = new UuidSegment();
        $uuid = $extractor->fromSegment('00000000-0000-7000-8000-000000000000');
        self::assertSame('00000000-0000-7000-8000-000000000000', $uuid->toRfc4122());
    }

    #[Test]
    public function uuid_segment_rejects_invalid(): void
    {
        $extractor = new UuidSegment();
        $this->expectException(ExtractorRejection::class);
        $extractor->fromSegment('not-a-uuid');
    }

    #[Test]
    public function json_marshaller_unmarshal_throws_body_parse_on_invalid_json(): void
    {
        $marshaller = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        $this->expectException(BodyParseException::class);
        $this->expectExceptionMessage('invalid JSON');
        $marshaller->unmarshal('{not json', stdClass::class);
    }

    #[Test]
    public function json_marshaller_unmarshal_throws_body_parse_on_type_mismatch(): void
    {
        $marshaller = new JsonValinorMarshaller((new MapperBuilder())->mapper());
        $this->expectException(BodyParseException::class);
        $marshaller->unmarshal('{"missing":"data"}', SimpleDto::class);
    }

    #[Test]
    public function marshaller_registry_default_throws_when_empty(): void
    {
        $registry = new MarshallerRegistry();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no marshaller registered');
        $registry->default();
    }

    #[Test]
    public function marshaller_registry_by_media_type_throws_when_no_match(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no marshaller for application/xml');
        $registry->byMediaType(new MediaType('application', 'xml'));
    }

    #[Test]
    public function marshaller_registry_by_media_type_finds_via_matches(): void
    {
        $registry = MarshallerRegistry::withDefaults();
        // matches() handles wildcards, but exact lookup goes through the
        // foreach branch when not found in the byMediaType map directly.
        $marshaller = $registry->byMediaType(new MediaType('application', 'json'));
        self::assertSame('application/json', (string) $marshaller->mediaType());
    }

    #[Test]
    public function marshaller_registry_by_media_type_finds_via_wildcard_marshaller(): void
    {
        // The matches() branch fires when a registered marshaller has a
        // wildcard mediaType. Then a concrete request goes through the
        // foreach loop, finds the wildcard marshaller, and returns it.
        $registry = new MarshallerRegistry();
        $registry->register(new WildcardMarshaller());
        $marshaller = $registry->byMediaType(new MediaType('text', 'plain'));
        self::assertSame('*/*', (string) $marshaller->mediaType());
    }

    #[Test]
    public function bearer_token_middleware_rejects_invalid_token(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/')->withHeader('Authorization', 'Bearer wrong');

        $middleware = new BearerTokenMiddleware(['secret']);
        $handler = self::okHandler();

        $response = $middleware->process($request, $handler);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('invalid_token', (string) $response->getBody());
    }

    #[Test]
    public function logging_middleware_logs_error_when_handler_throws(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', '/widgets');

        $logger = new RecordingLogger();
        $middleware = new LoggingMiddleware($logger);
        $handler = self::throwingHandler();

        try {
            $middleware->process($request, $handler);
            self::fail('expected exception to propagate');
        } catch (RuntimeException) {
            // expected
        }

        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('http_request_failed', $logger->records[0]['message']);
    }

    #[Test]
    public function logging_middleware_logs_error_for_5xx_status(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $logger = new RecordingLogger();
        $middleware = new LoggingMiddleware($logger);
        $handler = self::handlerWithStatus(503);

        $middleware->process($request, $handler);

        self::assertSame('error', $logger->records[0]['level']);
    }

    #[Test]
    public function logging_middleware_logs_notice_for_4xx_status(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/');
        $logger = new RecordingLogger();
        $middleware = new LoggingMiddleware($logger);
        $handler = self::handlerWithStatus(404);

        $middleware->process($request, $handler);

        self::assertSame('notice', $logger->records[0]['level']);
    }

    #[Test]
    public function path_state_from_path_handles_root(): void
    {
        $state = PathState::fromPath('/');
        self::assertTrue($state->isEmpty());
    }

    #[Test]
    public function path_state_from_path_handles_empty(): void
    {
        $state = PathState::fromPath('');
        self::assertTrue($state->isEmpty());
    }

    #[Test]
    public function path_state_consume_any_returns_null_when_empty(): void
    {
        $state = new PathState([]);
        self::assertNull($state->consumeAny());
    }

    #[Test]
    public function body_parse_exception_carries_message(): void
    {
        $exception = new BodyParseException('oops');
        self::assertSame('oops', $exception->getMessage());
    }

    private static function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }

    private static function throwingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new RuntimeException('boom');
            }
        };
    }

    private static function handlerWithStatus(int $status): RequestHandlerInterface
    {
        return new class ($status) implements RequestHandlerInterface {
            public function __construct(private int $status) {}

            #[Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->status);
            }
        };
    }
}

final readonly class SimpleDto
{
    public function __construct(public string $required) {}
}

final readonly class WildcardMarshaller implements Marshaller
{
    #[Override]
    public function mediaType(): MediaType
    {
        return new MediaType('*', '*');
    }

    /**
     * @template T
     * @param class-string<T> $targetType
     * @return T
     */
    #[Override]
    public function unmarshal(string $body, string $targetType): mixed
    {
        throw new RuntimeException('not implemented');
    }

    #[Override]
    public function marshal(mixed $value): string
    {
        return (string) $value;
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /** @param array<array-key, mixed> $context */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'context' => $context,
            'level' => (string) $level,
            'message' => (string) $message,
        ];
    }
}
