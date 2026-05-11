<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke;

use DateTimeImmutable;
use DateTimeZone;
use Monadial\Nexus\Ddd\Bus\Bus\SyncCommandBus;
use Monadial\Nexus\Ddd\Bus\Exception\RetryBudgetExhaustedException;
use Monadial\Nexus\Ddd\Bus\Tests\Unit\Smoke\Fixtures\PlaceOrder;
use Monadial\Nexus\Ddd\Core\Exception\OptimisticLockException;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LogLevel;

/**
 * H12 budget exhaustion: a handler that repeatedly throws `OptimisticLockException`
 * past the configured budget produces `Either::left(RetryBudgetExhaustedException)`,
 * a `ddd.command.retry_exhausted` metric, and a WARNING log entry.
 */
#[CoversClass(SyncCommandBus::class)]
final class OccRetryBudgetExhaustedSmokeTest extends TestCase
{
    #[Test]
    public function repeatedOccFailuresExhaustBudgetEmitMetricsAndLogWarning(): void
    {
        $harness = new PipelineHarness();
        $harness->retryBudgetMs = 50;
        $harness->clock = new OccRetryBudgetSmokeAdvancingClock(advanceMsOnRead: 100);
        $handler = new OccRetryBudgetSmokeAlwaysFailingHandler();
        $harness->register(PlaceOrder::class, OccRetryBudgetSmokeAlwaysFailingHandler::class, $handler);
        $bus = $harness->build();

        $result = $bus->tryDispatch(new PlaceOrder(customerId: 'cust-1', orderId: 'order-1'));

        self::assertTrue($result->isLeft());
        self::assertInstanceOf(RetryBudgetExhaustedException::class, $result->get());

        $metricNames = array_map(static fn(array $r): string => $r['name'], $harness->metrics->records);
        self::assertContains('ddd.command.retry_exhausted', $metricNames);

        $warnings = array_filter(
            $harness->logger->records,
            static fn(array $r): bool => $r['level'] === LogLevel::WARNING && $r['message'] === 'ddd.command.retry_exhausted',
        );
        self::assertCount(1, $warnings);
    }
}

final class OccRetryBudgetSmokeAlwaysFailingHandler implements CommandHandler
{
    public int $attempts = 0;

    public function __invoke(PlaceOrder $command): void
    {
        $this->attempts++;

        throw new OptimisticLockException(PlaceOrder::class, $command->orderId, 1, 2);
    }
}

/**
 * Advances by `$advanceMsOnRead` microseconds on every `now()` read so the
 * OCC retry middleware observes elapsed time exceeding the budget.
 */
final class OccRetryBudgetSmokeAdvancingClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(public readonly int $advanceMsOnRead = 100)
    {
        $this->now = new DateTimeImmutable('2026-05-10T00:00:00', new DateTimeZone('UTC'));
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        $value = $this->now;
        $modified = $this->now->modify('+' . ($this->advanceMsOnRead * 1_000) . ' microseconds');
        $this->now = $modified !== false
            ? $modified
            : $this->now;

        return $value;
    }
}
