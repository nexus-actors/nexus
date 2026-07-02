<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\Observability\RecordingObservability;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class ActorCellInstrumentationTest extends TestCase
{
    #[Test]
    public function userMessageProducesConsumerSpanAndMetrics(): void
    {
        $observability = new RecordingObservability();
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('test', $runtime, null, null, null, $observability);

        $behavior = Behavior::receive(static fn(ActorContext $ctx, object $msg): Behavior => Behavior::same());
        $ref = $system->spawn(Props::fromBehavior($behavior), 'greeter');
        $ref->tell(new InstrumentationPing());

        $runtime->scheduleOnce(Duration::millis(200), static fn() => $system->shutdown(Duration::seconds(1)));
        $system->run();

        $consumerSpans = array_values(array_filter(
            $observability->spans(),
            static fn($span): bool => $span->kind === SpanKind::Consumer,
        ));
        self::assertNotEmpty($consumerSpans);
        $span = $consumerSpans[0];
        self::assertStringContainsString('InstrumentationPing', $span->name);
        self::assertSame('nexus', $span->attributes['messaging.system']);
        self::assertSame('InstrumentationPing', $span->attributes['nexus.message.type']);
        self::assertTrue($span->ended);

        $processed = array_values(array_filter(
            $observability->metrics(),
            static fn($metric): bool => $metric->name === 'nexus.actor.messages.processed',
        ));
        self::assertNotEmpty($processed);
    }
}

final readonly class InstrumentationPing {}
