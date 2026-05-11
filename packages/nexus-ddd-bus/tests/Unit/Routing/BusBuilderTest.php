<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Routing;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Attribute\Idempotent;
use Monadial\Nexus\Ddd\Bus\Attribute\InProcess;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException;
use Monadial\Nexus\Ddd\Bus\Exception\InProcessConnectionMismatchException;
use Monadial\Nexus\Ddd\Bus\Exception\MissingAuthorizationDeciderException;
use Monadial\Nexus\Ddd\Bus\Exception\MissingValidatorException;
use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuilder;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingResolution;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingStrategy;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use NoDiscard;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(BusBuilder::class)]
final class BusBuilderTest extends TestCase
{
    #[Test]
    public function buildWithoutHandlersProducesEmptyResult(): void
    {
        $result = new BusBuilder()->build(
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
            routing: new Composite([], 'misc'),
        );

        self::assertSame([], $result->handlerMap);
        self::assertSame([], $result->customMiddlewares);
    }

    #[Test]
    public function registerHandlerProducesIndexedEntry(): void
    {
        $result = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, BusBuilderHandler::class)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertSame([BusBuilderMessage::class => BusBuilderHandler::class], $result->handlerMap);

        $entry = $result->index->lookup(BusBuilderMessage::class)->getUnsafe();
        self::assertInstanceOf(ResolvedAttributesEntry::class, $entry);
        self::assertSame(BusBuilderHandler::class, $entry->handlerClass);
        self::assertFalse($entry->authorizeBeforeValidate);
        self::assertFalse($entry->idempotencyOptedOut);
    }

    #[Test]
    public function validateWithoutValidatorThrows(): void
    {
        $builder = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, HandlerWithValidate::class);

        $this->expectException(MissingValidatorException::class);

        $builder->build(
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
            routing: new Composite([], 'misc'),
        );
    }

    #[Test]
    public function validateWithValidatorPasses(): void
    {
        $result = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, HandlerWithValidate::class)
            ->build(
                Profile::Sync,
                hasValidator: true,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertTrue($result->index->lookup(BusBuilderMessage::class)->isSome());
    }

    #[Test]
    public function authorizeWithoutDeciderThrows(): void
    {
        $builder = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, HandlerWithAuthorize::class);

        $this->expectException(MissingAuthorizationDeciderException::class);

        $builder->build(
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
            routing: new Composite([], 'misc'),
        );
    }

    #[Test]
    public function authorizeBeforeValidationFlipFlagIsTrueWhenAttributeRequests(): void
    {
        $result = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, HandlerWithAuthorizeBeforeValidation::class)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: true,
                routing: new Composite([], 'misc'),
            );

        self::assertTrue($result->index->lookup(BusBuilderMessage::class)->getUnsafe()->authorizeBeforeValidate);
    }

    #[Test]
    public function authorizeWithoutBeforeFlagDefaultsToFalse(): void
    {
        $result = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, HandlerWithAuthorize::class)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: true,
                routing: new Composite([], 'misc'),
            );

        self::assertFalse($result->index->lookup(BusBuilderMessage::class)->getUnsafe()->authorizeBeforeValidate);
    }

    #[Test]
    public function idempotentOffMarksEntryAsOptedOut(): void
    {
        $result = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, HandlerWithIdempotentOff::class)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertTrue($result->index->lookup(BusBuilderMessage::class)->getUnsafe()->isIdempotencyOptedOut());
    }

    #[Test]
    public function idempotentWithoutOffLeavesEntryOptedIn(): void
    {
        $result = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, HandlerWithIdempotentDefault::class)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertFalse($result->index->lookup(BusBuilderMessage::class)->getUnsafe()->isIdempotencyOptedOut());
    }

    #[Test]
    public function inProcessConnectionMismatchThrows(): void
    {
        $builder = new BusBuilder()
            ->registerHandler(BusBuilderEvent::class, HandlerWithInProcess::class)
            ->bindConnection(HandlerWithInProcess::class, 'shipments_write')
            ->bindConnection(BusBuilderEvent::class, 'orders_write');

        $this->expectException(InProcessConnectionMismatchException::class);

        $builder->build(
            Profile::Sync,
            hasValidator: false,
            hasDecider: false,
            routing: new Composite([], 'misc'),
        );
    }

    #[Test]
    public function compositeRoutingConflictPropagates(): void
    {
        $conflicting = new Composite(
            [
                new FixedRoutingStrategy(Option::some(new RoutingResolution('orders', FixedRoutingStrategy::class))),
                new AlternateFixedRoutingStrategy(
                    Option::some(new RoutingResolution('reporting', AlternateFixedRoutingStrategy::class)),
                ),
            ],
            'misc',
        );

        $builder = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, BusBuilderHandler::class);

        $this->expectException(DuplicateRoutingException::class);

        $builder->build(Profile::Sync, hasValidator: false, hasDecider: false, routing: $conflicting);
    }

    #[Test]
    public function withMiddlewareWithoutBeforeAppendsRegistrationWithNullStage(): void
    {
        $middleware = new RecordingMiddleware('custom');

        $result = new BusBuilder()
            ->withMiddleware($middleware)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertCount(1, $result->customMiddlewares);
        self::assertSame($middleware, $result->customMiddlewares[0]->middleware);
        self::assertNull($result->customMiddlewares[0]->before);
    }

    #[Test]
    public function withMiddlewareWithBeforeStageStoresStage(): void
    {
        $middleware = new RecordingMiddleware('custom');

        $result = new BusBuilder()
            ->withMiddleware($middleware, before: PipelineStage::Validation)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertCount(1, $result->customMiddlewares);
        self::assertSame(PipelineStage::Validation, $result->customMiddlewares[0]->before);
    }

    #[Test]
    public function withMiddlewarePreservesRegistrationOrderForSameStage(): void
    {
        $first = new RecordingMiddleware('first');
        $second = new RecordingMiddleware('second');

        $result = new BusBuilder()
            ->withMiddleware($first, before: PipelineStage::Handler)
            ->withMiddleware($second, before: PipelineStage::Handler)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertCount(2, $result->customMiddlewares);
        self::assertSame($first, $result->customMiddlewares[0]->middleware);
        self::assertSame($second, $result->customMiddlewares[1]->middleware);
    }

    #[Test]
    public function withMiddlewareMixedStagePreservesRegistrationOrder(): void
    {
        $stageBound = new RecordingMiddleware('stage');
        $appended = new RecordingMiddleware('appended');

        $result = new BusBuilder()
            ->withMiddleware($stageBound, before: PipelineStage::Handler)
            ->withMiddleware($appended)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertCount(2, $result->customMiddlewares);
        self::assertSame($stageBound, $result->customMiddlewares[0]->middleware);
        self::assertSame(PipelineStage::Handler, $result->customMiddlewares[0]->before);
        self::assertSame($appended, $result->customMiddlewares[1]->middleware);
        self::assertNull($result->customMiddlewares[1]->before);
    }

    #[Test]
    public function fluentChainingComposesRegistrationAndMiddleware(): void
    {
        $middleware = new RecordingMiddleware('chained');

        $result = new BusBuilder()
            ->registerHandler(BusBuilderMessage::class, BusBuilderHandler::class)
            ->withMiddleware($middleware)
            ->withMiddleware($middleware, before: PipelineStage::Validation)
            ->build(
                Profile::Sync,
                hasValidator: false,
                hasDecider: false,
                routing: new Composite([], 'misc'),
            );

        self::assertSame([BusBuilderMessage::class => BusBuilderHandler::class], $result->handlerMap);
        self::assertCount(2, $result->customMiddlewares);
    }

    #[Test]
    public function withMiddlewareCarriesNoDiscardAttribute(): void
    {
        $reflection = new ReflectionMethod(BusBuilder::class, 'withMiddleware');

        self::assertNotSame([], $reflection->getAttributes(NoDiscard::class));
    }
}

final class FixedRoutingStrategy implements RoutingStrategy
{
    /** @param Option<RoutingResolution> $answer */
    public function __construct(private readonly Option $answer) {}

    #[Override]
    public function resolve(string $messageClass): Option
    {
        return $this->answer;
    }
}

final class AlternateFixedRoutingStrategy implements RoutingStrategy
{
    /** @param Option<RoutingResolution> $answer */
    public function __construct(private readonly Option $answer) {}

    #[Override]
    public function resolve(string $messageClass): Option
    {
        return $this->answer;
    }
}

final readonly class BusBuilderMessage {}

final readonly class BusBuilderEvent {}

final class BusBuilderHandler
{
    public function handle(BusBuilderMessage $message): void
    {
        // fixture: BusBuilder reflects this method to build a ResolvedAttributesEntry; no runtime behavior is exercised.
    }
}

final class HandlerWithValidate
{
    #[Validate]
    public function handle(BusBuilderMessage $message): void
    {
        // fixture: the #[Validate] attribute is the test subject; body intentionally empty.
    }
}

final class HandlerWithAuthorize
{
    #[Authorize(policy: 'order.cancel')]
    public function handle(BusBuilderMessage $message): void
    {
        // fixture: the #[Authorize] attribute is the test subject; body intentionally empty.
    }
}

final class HandlerWithAuthorizeBeforeValidation
{
    #[Authorize(policy: 'order.cancel', before: 'validation')]
    public function handle(BusBuilderMessage $message): void
    {
        // fixture: the #[Authorize(before:)] flip flag is the test subject; body intentionally empty.
    }
}

#[Idempotent(off: true)]
final class HandlerWithIdempotentOff
{
    public function handle(BusBuilderMessage $message): void
    {
        // fixture: the class-level #[Idempotent(off: true)] attribute is the test subject; body intentionally empty.
    }
}

#[Idempotent]
final class HandlerWithIdempotentDefault
{
    public function handle(BusBuilderMessage $message): void
    {
        // fixture: the class-level #[Idempotent] default is the test subject; body intentionally empty.
    }
}

final class HandlerWithInProcess
{
    #[InProcess]
    public function on(BusBuilderEvent $event): void
    {
        // fixture: the #[InProcess] attribute drives boot validation; body intentionally empty.
    }
}
