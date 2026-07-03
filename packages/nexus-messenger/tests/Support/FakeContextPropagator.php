<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Support;

use Monadial\Nexus\Observability\Context\Baggage;
use Monadial\Nexus\Observability\Context\Context;
use Monadial\Nexus\Observability\Context\ContextPropagator;
use Monadial\Nexus\Observability\Trace\SpanContext;
use Override;

/**
 * Deterministic propagator for tests. inject() stamps a known marker into the
 * carrier; extract() restores a valid (non-root) Context when the marker is
 * present, making parent-linking assertions straightforward.
 */
final class FakeContextPropagator implements ContextPropagator
{
    private const string HEADER = 'traceparent';
    private const string MARKER = 'fake-trace-id';

    /** @param array<string, string> $carrier */
    #[Override]
    public function inject(Context $context, array &$carrier): void
    {
        $carrier[self::HEADER] = self::MARKER;
    }

    /** @param array<string, string> $carrier */
    #[Override]
    public function extract(array $carrier, ?Context $context = null): Context
    {
        if (($carrier[self::HEADER] ?? null) !== self::MARKER) {
            return $context ?? Context::root();
        }

        return new Context(
            new SpanContext(
                str_repeat('a', 32),
                str_repeat('b', 16),
                1,
                true,
            ),
            Baggage::empty(),
        );
    }
}
