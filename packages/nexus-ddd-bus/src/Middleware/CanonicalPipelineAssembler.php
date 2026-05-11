<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use LogicException;
use Monadial\Nexus\Ddd\Bus\Authorization\AuthorizationDecider;
use Monadial\Nexus\Ddd\Bus\Authorization\PrincipalProvider;
use Monadial\Nexus\Ddd\Bus\Authorization\SubjectResolver;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyKeyResolver;
use Monadial\Nexus\Ddd\Bus\Idempotency\IdempotencyStore;
use Monadial\Nexus\Ddd\Bus\Logging\PayloadRedactor;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuildResult;
use Monadial\Nexus\Ddd\Bus\Routing\CustomMiddlewareRegistration;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Bus\Sleep\SleepStrategy;
use Monadial\Nexus\Ddd\Bus\Validation\Validator;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

use function array_splice;
use function sprintf;

/**
 * @psalm-api
 *
 * Production pipeline assembler (panel B1 + H1). Walks
 * `BusBuildResult::$handlerMap` and produces one `MiddlewarePipeline` per
 * handler with:
 *
 *   - The canonical 14-stage order:
 *     Causation → OtelSpan → LoggingStart → MetricsStart →
 *     Validation → Authorization → IdempotencyReserve → OccRetry →
 *     Handler → IdempotencyCommit → EventDrain → MetricsEnd →
 *     LoggingEnd → SpanClose.
 *   - Per-handler Authorize-before-Validate flip (panel H4) applied at
 *     boot when the handler entry's `authorizeBeforeValidate` is true:
 *     Authorization at slot 5, Validation at slot 6.
 *   - Adopter-supplied middleware (panel H13) spliced from
 *     `BusBuildResult::$customMiddlewares` immediately before the
 *     canonical stage named by each registration's `$before`, or
 *     appended after `SpanClose` when `$before === null`. Multiple
 *     registrations sharing the same `$before` preserve registration
 *     order.
 *
 * The output map is wrapped in `PerHandlerPipeline` and passed to a
 * `Sync*Bus` constructor (which now takes `EnvelopePipeline`).
 */
final class CanonicalPipelineAssembler
{
    public function __construct(
        private readonly Profile $profile,
        private readonly Validator $validator,
        private readonly AuthorizationDecider $decider,
        private readonly SubjectResolver $subjectResolver,
        private readonly PrincipalProvider $principalProvider,
        private readonly MessageContextStack $contextStack,
        private readonly IdempotencyStore $idempotencyStore,
        private readonly IdempotencyKeyResolver $keyResolver,
        private readonly MetricsCollector $metrics,
        private readonly LoggerInterface $logger,
        private readonly PayloadRedactor $redactor,
        private readonly ClockInterface $clock,
        private readonly BackoffStrategy $backoff,
        private readonly SleepStrategy $sleep,
        private readonly Outbox $outbox,
        private readonly CommandHandlerLocator $handlerLocator,
        private readonly int $causationDepthCap = 32,
        private readonly int $retryBudgetMs = 5_000,
        private readonly bool $logPayloadAtDebug = false,
    ) {}

    /**
     * @param Closure(\Monadial\Nexus\Ddd\Messaging\Envelope\Envelope): mixed $core
     *   Terminal closure invoked at the innermost layer. The canonical
     *   pipeline invokes the handler at stage 9 via
     *   `HandlerInvocationMiddleware`; for command and event buses the
     *   core is a no-op (the void-returning stages after Handler trust
     *   `$next` to return null), while for query buses the core forwards
     *   the result captured by an adapter middleware.
     * @return array<class-string, MiddlewarePipeline>  message class → pipeline
     */
    public function assemble(BusBuildResult $result, Closure $core): array
    {
        $pipelines = [];

        foreach ($result->handlerMap as $messageClass => $_handlerClass) {
            $entry = $result->index->lookup($messageClass)->getUnsafe();
            $stages = $this->canonicalStagesForHandler($result->index, $entry->authorizeBeforeValidate);
            $spliced = $this->spliceCustomMiddleware($stages, $result->customMiddlewares);
            $pipelines[$messageClass] = new MiddlewarePipeline($spliced, $core);
        }

        return $pipelines;
    }

    /**
     * Convenience: assemble the per-handler map, attach a fallback
     * pipeline (canonical order, no splices), and wrap in a
     * `PerHandlerPipeline` ready to hand to a `Sync*Bus` constructor.
     *
     * @param Closure(\Monadial\Nexus\Ddd\Messaging\Envelope\Envelope): mixed $core
     */
    public function assembleEnvelopePipeline(BusBuildResult $result, Closure $core): PerHandlerPipeline
    {
        $perHandler = $this->assemble($result, $core);
        $fallback = new MiddlewarePipeline(
            $this->canonicalStagesForHandler($result->index, false),
            $core,
        );

        return new PerHandlerPipeline($perHandler, $fallback);
    }

    /**
     * @return list<Middleware>  outermost-first
     */
    private function canonicalStagesForHandler(HandlerAttributeIndex $index, bool $authorizeBeforeValidate): array
    {
        $causation = new CausationPropagationMiddleware($this->causationDepthCap);
        $otel = new OpenTelemetrySpanMiddleware();
        $logStart = new LoggingStartMiddleware($this->logger, $this->redactor, $this->logPayloadAtDebug);
        $metricsStart = new MetricsStartMiddleware($this->metrics);
        $validation = new ValidationMiddleware($this->validator, $index);
        $authorization = new AuthorizationMiddleware(
            $this->decider,
            $this->subjectResolver,
            $index,
            $this->contextStack,
            $this->principalProvider,
        );
        $idempotencyReserve = new IdempotencyReserveMiddleware(
            $this->idempotencyStore,
            $this->keyResolver,
            $index,
            $this->profile,
            $this->metrics,
            $this->logger,
        );
        $occRetry = new OccRetryMiddleware(
            $this->profile,
            $this->backoff,
            $this->clock,
            $this->logger,
            $this->metrics,
            $this->retryBudgetMs,
            $this->sleep,
        );
        $handler = new HandlerInvocationMiddleware($this->handlerLocator);
        $idempotencyCommit = new IdempotencyCommitMiddleware($this->idempotencyStore, $this->profile);
        $eventDrain = new EventDrainMiddleware($this->outbox);
        $metricsEnd = new MetricsEndMiddleware($this->metrics);
        $logEnd = new LoggingEndMiddleware($this->logger);
        $spanClose = new SpanCloseMiddleware();

        $slot5 = $authorizeBeforeValidate
            ? $authorization
            : $validation;
        $slot6 = $authorizeBeforeValidate
            ? $validation
            : $authorization;

        return [
            $causation,
            $otel,
            $logStart,
            $metricsStart,
            $slot5,
            $slot6,
            $idempotencyReserve,
            $occRetry,
            $handler,
            $idempotencyCommit,
            $eventDrain,
            $metricsEnd,
            $logEnd,
            $spanClose,
        ];
    }

    /**
     * @param list<Middleware> $canonical
     * @param list<CustomMiddlewareRegistration> $custom
     * @return list<Middleware>
     */
    private function spliceCustomMiddleware(array $canonical, array $custom): array
    {
        $output = $canonical;

        foreach ($custom as $registration) {
            if ($registration->before === null) {
                $output[] = $registration->middleware;

                continue;
            }

            $stageClass = self::middlewareClassForStage($registration->before);
            $position = self::findPositionByClass($output, $stageClass);
            array_splice($output, $position, 0, [$registration->middleware]);
        }

        return $output;
    }

    /** @return class-string<Middleware> */
    private static function middlewareClassForStage(PipelineStage $stage): string
    {
        return match ($stage) {
            PipelineStage::Causation => CausationPropagationMiddleware::class,
            PipelineStage::OtelSpan => OpenTelemetrySpanMiddleware::class,
            PipelineStage::LoggingStart => LoggingStartMiddleware::class,
            PipelineStage::MetricsStart => MetricsStartMiddleware::class,
            PipelineStage::Validation => ValidationMiddleware::class,
            PipelineStage::Authorization => AuthorizationMiddleware::class,
            PipelineStage::IdempotencyReserve => IdempotencyReserveMiddleware::class,
            PipelineStage::OccRetry => OccRetryMiddleware::class,
            PipelineStage::Handler => HandlerInvocationMiddleware::class,
            PipelineStage::IdempotencyCommit => IdempotencyCommitMiddleware::class,
            PipelineStage::EventDrain => EventDrainMiddleware::class,
            PipelineStage::MetricsEnd => MetricsEndMiddleware::class,
            PipelineStage::LoggingEnd => LoggingEndMiddleware::class,
            PipelineStage::SpanClose => SpanCloseMiddleware::class,
        };
    }

    /**
     * @param list<Middleware> $stages
     * @param class-string<Middleware> $target
     */
    private static function findPositionByClass(array $stages, string $target): int
    {
        foreach ($stages as $position => $middleware) {
            if ($middleware::class === $target) {
                return $position;
            }
        }

        throw new LogicException(sprintf('Canonical pipeline missing stage %s', $target));
    }
}
