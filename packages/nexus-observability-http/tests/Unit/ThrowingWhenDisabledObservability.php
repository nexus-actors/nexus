<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Http\Tests\Unit;

use LogicException;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Metric\Meter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Observability\Trace\Tracer;
use Override;

final class ThrowingWhenDisabledObservability implements Observability
{
    #[Override]
    public function isEnabled(): bool
    {
        return false;
    }

    #[Override]
    public function tracer(): Tracer
    {
        throw new LogicException('telemetry accessed while disabled');
    }

    #[Override]
    public function meter(): Meter
    {
        throw new LogicException('telemetry accessed while disabled');
    }

    #[Override]
    public function propagator(): ContextPropagator
    {
        throw new LogicException('telemetry accessed while disabled');
    }

    #[Override]
    public function currentContext(): Context
    {
        throw new LogicException('telemetry accessed while disabled');
    }

    #[Override]
    public function shutdown(): void {}
}
