<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Tests\Unit;

use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\NoopContextPropagator;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\NoopObservability;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoopObservability::class)]
final class NoopObservabilityTest extends TestCase
{
    #[Test]
    public function exposesNoopProviders(): void
    {
        $observability = new NoopObservability();

        self::assertInstanceOf(NoopTracer::class, $observability->tracer());
        self::assertInstanceOf(NoopMeter::class, $observability->meter());
        self::assertInstanceOf(NoopContextPropagator::class, $observability->propagator());
    }

    #[Test]
    public function propagatorExtractsRoot(): void
    {
        $context = (new NoopObservability())->propagator()->extract(['traceparent' => '00-x']);

        self::assertFalse($context->spanContext->isValid());
        self::assertInstanceOf(Context::class, $context);
    }

    #[Test]
    public function currentContextIsRoot(): void
    {
        self::assertFalse((new NoopObservability())->currentContext()->spanContext->isValid());
    }

    #[Test]
    public function isNotEnabled(): void
    {
        self::assertFalse((new NoopObservability())->isEnabled());
    }

    #[Test]
    public function shutdownIsNoOp(): void
    {
        (new NoopObservability())->shutdown();

        self::expectNotToPerformAssertions();
    }
}
