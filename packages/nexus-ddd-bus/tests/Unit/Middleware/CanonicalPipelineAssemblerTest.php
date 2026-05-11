<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Middleware;

use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Bus\Authorization\NoPrincipalProvider;
use Monadial\Nexus\Ddd\Bus\Authorization\SubjectResolver;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKeyResolver;
use Monadial\Nexus\Ddd\Bus\Logging\PayloadRedactor;
use Monadial\Nexus\Ddd\Bus\Middleware\AuthorizationMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\CanonicalPipelineAssembler;
use Monadial\Nexus\Ddd\Bus\Middleware\CausationPropagationMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\EventDrainMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\HandlerInvocationMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\IdempotencyCommitMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\IdempotencyReserveMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\LoggingEndMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\LoggingStartMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\MetricsEndMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\MetricsStartMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\Middleware;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Middleware\OccRetryMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\OpenTelemetrySpanMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\PerHandlerPipeline;
use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;
use Monadial\Nexus\Ddd\Bus\Middleware\SpanCloseMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\ValidationMiddleware;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuildResult;
use Monadial\Nexus\Ddd\Bus\Routing\CustomMiddlewareRegistration;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Routing\ResolvedAttributesEntry;
use Monadial\Nexus\Ddd\Bus\Sleep\BlockingSleepStrategy;
use Monadial\Nexus\Ddd\Bus\Tests\Support\FixedClock;
use Monadial\Nexus\Ddd\Bus\Tests\Support\MapCommandHandlerLocator;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingAuthorizationDecider;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingIdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingLogger;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMetricsCollector;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMiddleware;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingOutbox;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingValidator;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

#[CoversClass(CanonicalPipelineAssembler::class)]
final class CanonicalPipelineAssemblerTest extends TestCase
{
    private const array CANONICAL_DEFAULT = [
        CausationPropagationMiddleware::class,
        OpenTelemetrySpanMiddleware::class,
        LoggingStartMiddleware::class,
        MetricsStartMiddleware::class,
        ValidationMiddleware::class,
        AuthorizationMiddleware::class,
        IdempotencyReserveMiddleware::class,
        OccRetryMiddleware::class,
        HandlerInvocationMiddleware::class,
        IdempotencyCommitMiddleware::class,
        EventDrainMiddleware::class,
        MetricsEndMiddleware::class,
        LoggingEndMiddleware::class,
        SpanCloseMiddleware::class,
    ];

    #[Test]
    public function singleHandlerWithoutFlipUsesCanonicalOrder(): void
    {
        $result = $this->busBuildResult([
            FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: false),
        ], []);
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);

        self::assertArrayHasKey(FakeAssemblerCommand::class, $pipelines);
        self::assertSame(self::CANONICAL_DEFAULT, $this->stageClasses($pipelines[FakeAssemblerCommand::class]));
    }

    #[Test]
    public function handlerWithAuthorizeBeforeValidateFlipsSlots5And6(): void
    {
        $result = $this->busBuildResult([
            FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: true),
        ], []);
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);
        $stages = $this->stageClasses($pipelines[FakeAssemblerCommand::class]);

        self::assertSame(AuthorizationMiddleware::class, $stages[4]);
        self::assertSame(ValidationMiddleware::class, $stages[5]);

        $expected = self::CANONICAL_DEFAULT;
        $expected[4] = AuthorizationMiddleware::class;
        $expected[5] = ValidationMiddleware::class;
        self::assertSame($expected, $stages);
    }

    #[Test]
    public function multipleHandlersWithDifferentFlipsProduceDistinctPipelines(): void
    {
        $result = $this->busBuildResult([
            AnotherAssemblerCommand::class => $this->entry(authorizeBeforeValidate: true),
            FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: false),
        ], []);
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);

        $defaultStages = $this->stageClasses($pipelines[FakeAssemblerCommand::class]);
        $flippedStages = $this->stageClasses($pipelines[AnotherAssemblerCommand::class]);

        self::assertSame(ValidationMiddleware::class, $defaultStages[4]);
        self::assertSame(AuthorizationMiddleware::class, $defaultStages[5]);
        self::assertSame(AuthorizationMiddleware::class, $flippedStages[4]);
        self::assertSame(ValidationMiddleware::class, $flippedStages[5]);
    }

    #[Test]
    public function customMiddlewareWithoutBeforeIsAppendedAfterSpanClose(): void
    {
        $custom = new RecordingMiddleware('custom-append');
        $result = $this->busBuildResult(
            [FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: false)],
            [new CustomMiddlewareRegistration($custom, null)],
        );
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);
        $middlewares = $this->stageInstances($pipelines[FakeAssemblerCommand::class]);

        self::assertCount(15, $middlewares);
        self::assertInstanceOf(SpanCloseMiddleware::class, $middlewares[13]);
        self::assertSame($custom, $middlewares[14]);
    }

    #[Test]
    public function customMiddlewareSplicesBeforeNamedCanonicalStage(): void
    {
        $custom = new RecordingMiddleware('before-validation');
        $result = $this->busBuildResult(
            [FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: false)],
            [new CustomMiddlewareRegistration($custom, PipelineStage::Validation)],
        );
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);
        $middlewares = $this->stageInstances($pipelines[FakeAssemblerCommand::class]);

        self::assertCount(15, $middlewares);
        self::assertSame($custom, $middlewares[4]);
        self::assertInstanceOf(ValidationMiddleware::class, $middlewares[5]);
        self::assertInstanceOf(AuthorizationMiddleware::class, $middlewares[6]);
    }

    #[Test]
    public function customMiddlewareSplicesBeforeFlippedValidationStage(): void
    {
        $custom = new RecordingMiddleware('before-validation-flipped');
        $result = $this->busBuildResult(
            [FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: true)],
            [new CustomMiddlewareRegistration($custom, PipelineStage::Validation)],
        );
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);
        $middlewares = $this->stageInstances($pipelines[FakeAssemblerCommand::class]);

        self::assertInstanceOf(AuthorizationMiddleware::class, $middlewares[4]);
        self::assertSame($custom, $middlewares[5]);
        self::assertInstanceOf(ValidationMiddleware::class, $middlewares[6]);
    }

    #[Test]
    public function multipleCustomMiddlewareWithSameBeforePreserveRegistrationOrder(): void
    {
        $first = new RecordingMiddleware('first-before-handler');
        $second = new RecordingMiddleware('second-before-handler');
        $result = $this->busBuildResult(
            [FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: false)],
            [
                new CustomMiddlewareRegistration($first, PipelineStage::Handler),
                new CustomMiddlewareRegistration($second, PipelineStage::Handler),
            ],
        );
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);
        $middlewares = $this->stageInstances($pipelines[FakeAssemblerCommand::class]);

        $firstIdx = (int) array_search($first, $middlewares, true);
        $secondIdx = (int) array_search($second, $middlewares, true);
        self::assertLessThan($secondIdx, $firstIdx);
        self::assertSame(HandlerInvocationMiddleware::class, $middlewares[$secondIdx + 1]::class);
    }

    #[Test]
    public function emptyHandlerMapProducesEmptyPipelineMap(): void
    {
        $result = new BusBuildResult(new HandlerAttributeIndex([]), [], []);
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);

        self::assertSame([], $pipelines);
    }

    #[Test]
    public function assembleEnvelopePipelineWrapsMapInPerHandlerPipeline(): void
    {
        $result = $this->busBuildResult(
            [FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: false)],
            [],
        );

        $pipeline = $this->assembler()->assembleEnvelopePipeline($result, static fn(Envelope $_e): mixed => null);

        self::assertInstanceOf(PerHandlerPipeline::class, $pipeline);
    }

    #[Test]
    public function customMiddlewareBeforeFirstStageBecomesNewOutermost(): void
    {
        $custom = new RecordingMiddleware('before-causation');
        $result = $this->busBuildResult(
            [FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: false)],
            [new CustomMiddlewareRegistration($custom, PipelineStage::Causation)],
        );
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);
        $middlewares = $this->stageInstances($pipelines[FakeAssemblerCommand::class]);

        self::assertSame($custom, $middlewares[0]);
        self::assertInstanceOf(CausationPropagationMiddleware::class, $middlewares[1]);
    }

    #[Test]
    public function customMiddlewareBeforeSpanCloseIsPenultimate(): void
    {
        $custom = new RecordingMiddleware('before-span-close');
        $result = $this->busBuildResult(
            [FakeAssemblerCommand::class => $this->entry(authorizeBeforeValidate: false)],
            [new CustomMiddlewareRegistration($custom, PipelineStage::SpanClose)],
        );
        $pipelines = $this->assembler()->assemble($result, static fn(Envelope $_e): mixed => null);
        $middlewares = $this->stageInstances($pipelines[FakeAssemblerCommand::class]);

        self::assertCount(15, $middlewares);
        self::assertSame($custom, $middlewares[13]);
        self::assertInstanceOf(SpanCloseMiddleware::class, $middlewares[14]);
    }

    private function entry(bool $authorizeBeforeValidate): ResolvedAttributesEntry
    {
        return new ResolvedAttributesEntry(
            handlerClass: 'App\\Handler\\Fake',
            attributes: [],
            authorizeBeforeValidate: $authorizeBeforeValidate,
            idempotencyOptedOut: false,
        );
    }

    /**
     * @param array<class-string, ResolvedAttributesEntry> $entries
     * @param list<CustomMiddlewareRegistration> $custom
     */
    private function busBuildResult(array $entries, array $custom): BusBuildResult
    {
        $handlerMap = [];

        foreach ($entries as $messageClass => $entry) {
            $handlerMap[$messageClass] = $entry->handlerClass;
        }

        return new BusBuildResult(new HandlerAttributeIndex($entries), $handlerMap, $custom);
    }

    private function assembler(): CanonicalPipelineAssembler
    {
        return new CanonicalPipelineAssembler(
            Profile::Sync,
            RecordingValidator::returningEmpty(),
            RecordingAuthorizationDecider::allowing(),
            new SubjectResolver(),
            new NoPrincipalProvider(),
            MessageContextStack::default(),
            new RecordingIdempotencyStore(),
            new IdempotencyKeyResolver(),
            new RecordingMetricsCollector(),
            new RecordingLogger(),
            new PayloadRedactor(),
            new FixedClock(),
            new AssemblerZeroDelayBackoff(),
            new BlockingSleepStrategy(),
            new RecordingOutbox(),
            new MapCommandHandlerLocator(),
        );
    }

    /**
     * @return list<class-string<Middleware>>
     */
    private function stageClasses(MiddlewarePipeline $pipeline): array
    {
        return array_map(static fn(Middleware $m): string => $m::class, $this->stageInstances($pipeline));
    }

    /** @return list<Middleware> */
    private function stageInstances(MiddlewarePipeline $pipeline): array
    {
        $reflection = new ReflectionClass($pipeline);
        $property = $reflection->getProperty('middlewares');
        /** @var list<Middleware> $value */
        $value = $property->getValue($pipeline);

        return $value;
    }
}

final readonly class FakeAssemblerCommand implements Command {}

final readonly class AnotherAssemblerCommand implements Command {}

final readonly class AssemblerZeroDelayBackoff implements BackoffStrategy
{
    /** @return Option<FiniteDuration> */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return Option::some(FiniteDuration::fromTimeUnit(0, TimeUnit::Microseconds()));
    }
}
