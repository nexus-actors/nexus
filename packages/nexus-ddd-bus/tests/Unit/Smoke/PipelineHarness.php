<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use Closure;
use Fp\Functional\Option\Option;
use Monadial\Duration\FiniteDuration;
use Monadial\Duration\TimeUnit\TimeUnit;
use Monadial\Nexus\Ddd\Bus\Authorization\SubjectResolver;
use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKeyResolver;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Logging\PayloadRedactor;
use Monadial\Nexus\Ddd\Bus\Middleware\AuthorizationMiddleware;
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
use Monadial\Nexus\Ddd\Bus\Middleware\SpanCloseMiddleware;
use Monadial\Nexus\Ddd\Bus\Middleware\ValidationMiddleware;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuilder;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuildResult;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Monadial\Nexus\Ddd\Bus\Tests\Support\FixedClock;
use Monadial\Nexus\Ddd\Bus\Tests\Support\MapCommandHandlerLocator;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingAuthorizationDecider;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingIdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingLogger;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingMetricsCollector;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingOutbox;
use Monadial\Nexus\Ddd\Bus\Tests\Support\RecordingValidator;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy;
use Override;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Test-only assembler that wires the full canonical 14-stage pipeline plus
 * a `SyncCommandBus`. Smoke tests override the slots they care about and
 * call `build()` to get a configured bus. The harness keeps the slot
 * defaults aligned with the production wiring (`Profile::Sync`,
 * zero-delay backoff, in-memory recording fixtures).
 *
 * The pipeline ordering mirrors `PipelineStage`:
 *   Causation → OtelSpan → LoggingStart → MetricsStart → Validation →
 *   Authorization → IdempotencyReserve → OccRetry → Handler →
 *   IdempotencyCommit → EventDrain → MetricsEnd → LoggingEnd → SpanClose.
 *
 * @psalm-api
 */
final class PipelineHarness
{
    public Profile $profile = Profile::Sync;

    public RecordingValidator $validator;

    public RecordingAuthorizationDecider $decider;

    public IdempotencyStore $idempotencyStore;

    public RecordingMetricsCollector $metrics;

    public RecordingLogger $logger;

    public Outbox $outbox;

    public MapCommandHandlerLocator $locator;

    public BusBuilder $builder;

    public ClockInterface $clock;

    public BackoffStrategy $backoff;

    public int $retryBudgetMs = 5_000;

    public int $causationDepthCap = 32;

    public bool $hasValidator = true;

    public bool $hasDecider = true;

    /** @var list<Middleware> outermost first; appended to the canonical stack */
    public array $extraMiddlewares = [];

    public function __construct()
    {
        $this->validator = RecordingValidator::returningEmpty();
        $this->decider = RecordingAuthorizationDecider::allowing();
        $this->idempotencyStore = new RecordingIdempotencyStore();
        $this->metrics = new RecordingMetricsCollector();
        $this->logger = new RecordingLogger();
        $this->outbox = new RecordingOutbox();
        $this->locator = new MapCommandHandlerLocator();
        $this->builder = new BusBuilder();
        $this->clock = new FixedClock();
        $this->backoff = new SmokeZeroDelayBackoff();
    }

    /**
     * @param class-string<Command> $commandClass
     * @param class-string $handlerClass
     */
    public function register(string $commandClass, string $handlerClass, CommandHandler $instance): void
    {
        (void) $this->builder->registerHandler($commandClass, $handlerClass);
        $this->locator->register($commandClass, $instance);
    }

    public function build(): SyncCommandBus
    {
        $result = $this->builder->build(
            $this->profile,
            hasValidator: $this->hasValidator,
            hasDecider: $this->hasDecider,
            routing: new Composite([], 'default'),
        );

        $registry = new BusRegistry($this->profile, [], [], []);

        return new SyncCommandBus(
            $registry,
            $result->index,
            $this->pipeline($result),
            $this->profile,
            $this->clock,
        );
    }

    /**
     * @return MiddlewarePipeline<Command, mixed>
     */
    private function pipeline(BusBuildResult $result): MiddlewarePipeline
    {
        $contextStack = MessageContextStack::default();

        $canonical = [
            new CausationPropagationMiddleware($this->causationDepthCap),
            new OpenTelemetrySpanMiddleware(),
            new LoggingStartMiddleware($this->logger, new PayloadRedactor()),
            new MetricsStartMiddleware($this->metrics),
            new ValidationMiddleware($this->validator, $result->index),
            new AuthorizationMiddleware($this->decider, new SubjectResolver(), $result->index, $contextStack),
            new IdempotencyReserveMiddleware(
                $this->idempotencyStore,
                new IdempotencyKeyResolver(),
                $result->index,
                $this->profile,
            ),
            new OccRetryMiddleware(
                $this->profile,
                $this->backoff,
                $this->clock,
                $this->logger,
                $this->metrics,
                $this->retryBudgetMs,
            ),
            new HandlerInvocationMiddleware($this->locator),
            new IdempotencyCommitMiddleware($this->idempotencyStore, $this->profile),
            new EventDrainMiddleware($this->outbox),
            new MetricsEndMiddleware($this->metrics),
            new LoggingEndMiddleware($this->logger),
            new SpanCloseMiddleware(),
        ];

        $stack = [...$this->extraMiddlewares, ...$canonical];

        /** @var Closure(Envelope<Command>): mixed $core */
        $core = static fn(Envelope $e): mixed => null;

        return new MiddlewarePipeline($stack, $core);
    }
}

/**
 * Inline test helper: returns `Some(0 microseconds)` so `OccRetryMiddleware`
 * proceeds with the next retry attempt without sleeping. Lives here so the
 * smoke harness has a deterministic backoff without touching production
 * `BackoffStrategy` impls.
 */
final readonly class SmokeZeroDelayBackoff implements BackoffStrategy
{
    /** @return Option<FiniteDuration> */
    #[Override]
    public function delayFor(int $attempt, Throwable $cause): Option
    {
        return Option::some(FiniteDuration::fromTimeUnit(0, TimeUnit::Microseconds()));
    }
}
