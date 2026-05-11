<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Middleware;

use Closure;
use DateTimeImmutable;
use Monadial\Duration\FiniteDuration;
use Monadial\Nexus\Ddd\Bus\Exception\ActorWriterInvariantViolation;
use Monadial\Nexus\Ddd\Bus\Exception\RetryBudgetExhaustedException;
use Monadial\Nexus\Ddd\Bus\Metrics\MetricsCollector;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Retry\BackoffStrategy;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

use function usleep;

/**
 * @psalm-api
 *
 * Host-aware OCC retry (per panel H12). Under `Profile::Sync` and
 * `Profile::Async` the middleware retries `OptimisticLockException` until
 * the time budget is exhausted; on exhaustion it emits a
 * `ddd.command.retry_exhausted` counter, logs a `WARNING`, and throws
 * `RetryBudgetExhaustedException`. Under `Profile::Actor` the single-writer
 * invariant means an OCC collision is a wiring fault — the middleware
 * wraps it as `ActorWriterInvariantViolation` (terminal) and rethrows
 * without retrying.
 *
 * Elapsed time is computed at microsecond precision via the injected
 * `Psr\Clock\ClockInterface` so budgets shorter than one second are
 * actually enforced.
 *
 * @template TIn of object
 * @template TOut
 * @implements Middleware<TIn, TOut>
 */
final class OccRetryMiddleware implements Middleware
{
    public function __construct(
        private readonly Profile $profile,
        private readonly BackoffStrategy $backoff,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly MetricsCollector $metrics,
        private readonly int $defaultBudgetMs,
    ) {}

    #[Override]
    public function process(Envelope $envelope, Closure $next): mixed
    {
        if ($this->profile === Profile::Actor) {
            try {
                return $next($envelope);
            } catch (OptimisticLockException $e) {
                throw ActorWriterInvariantViolation::forOptimisticLock(
                    $e->entityClass,
                    $e->entityId,
                    $e->expectedVersion,
                    $e->actualVersion,
                );
            }
        }

        $start = $this->clock->now();
        $attempt = 0;

        while (true) {
            try {
                return $next($envelope);
            } catch (OptimisticLockException $e) {
                $attempt++;
                $elapsedMs = self::elapsedMillis($start, $this->clock->now());

                if ($elapsedMs >= $this->defaultBudgetMs) {
                    $this->metrics->count('ddd.command.retry_exhausted', 1, ['type' => $envelope->message::class]);
                    $this->logger->log(LogLevel::WARNING, 'ddd.command.retry_exhausted', [
                        'attempts' => $attempt,
                        'budget_ms' => $this->defaultBudgetMs,
                        'cause' => $e->getMessage(),
                        'messageId' => $envelope->metadata->id->value(),
                        'type' => $envelope->message::class,
                    ]);

                    throw RetryBudgetExhaustedException::for($attempt, $this->defaultBudgetMs, $e);
                }

                $this->backoff->delayFor($attempt, $e)
                    ->tap(static fn(FiniteDuration $delay) => usleep($delay->toMicros()));
            }
        }
    }

    private static function elapsedMillis(DateTimeImmutable $start, DateTimeImmutable $now): int
    {
        $secondsDiff = $now->getTimestamp() - $start->getTimestamp();
        $microsDiff = (int) $now->format('u') - (int) $start->format('u');

        return ($secondsDiff * 1000) + (int) ($microsDiff / 1000);
    }
}
