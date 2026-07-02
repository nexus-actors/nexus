<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit\Metric;

use Monadial\Nexus\Observability\Metric\NoopCounter;
use Monadial\Nexus\Observability\Metric\NoopHistogram;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Metric\NoopObservableGauge;
use Monadial\Nexus\Observability\Metric\NoopUpDownCounter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoopMeter::class)]
#[CoversClass(NoopCounter::class)]
#[CoversClass(NoopHistogram::class)]
#[CoversClass(NoopObservableGauge::class)]
#[CoversClass(NoopUpDownCounter::class)]
final class NoopMeterTest extends TestCase
{
    #[Test]
    public function instrumentsAreCreatedAndDoNotThrow(): void
    {
        $meter = new NoopMeter();

        $counter = $meter->counter('nexus.messages.processed', '{message}', 'Messages processed');
        $counter->add(1, ['nexus.message.type' => 'Greet']);

        $upDown = $meter->upDownCounter('nexus.actor.mailbox.size');
        $upDown->add(-1);

        $histogram = $meter->histogram('nexus.message.processing.duration', 'ms');
        $histogram->record(12.5, ['nexus.message.type' => 'Greet']);

        $gauge = $meter->observableGauge('nexus.runtime.coroutines', static fn (): int => 3);

        self::assertInstanceOf(NoopCounter::class, $counter);
        self::assertInstanceOf(NoopUpDownCounter::class, $upDown);
        self::assertInstanceOf(NoopHistogram::class, $histogram);
        self::assertInstanceOf(NoopObservableGauge::class, $gauge);
    }
}
