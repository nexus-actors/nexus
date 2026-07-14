<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

use Monadial\Nexus\Observability\Trace\SpanContext;
use Override;

use function hexdec;
use function preg_match;
use function sprintf;

/**
 * @psalm-api
 *
 * W3C Trace Context propagator (`traceparent` / `tracestate`). Preserves any
 * baggage on the incoming accumulator context.
 *
 * @see https://www.w3.org/TR/trace-context/
 */
final class TraceContextPropagator implements ContextPropagator
{
    private const string TRACEPARENT = 'traceparent';
    private const string TRACESTATE = 'tracestate';
    private const string TRACEPARENT_PATTERN = '/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/';

    #[Override]
    public function inject(Context $context, array &$carrier): void
    {
        $spanContext = $context->spanContext;

        if (!$spanContext->isValid()) {
            return;
        }

        $carrier[self::TRACEPARENT] = sprintf(
            '00-%s-%s-%02x',
            $spanContext->traceId,
            $spanContext->spanId,
            $spanContext->traceFlags,
        );

        if ($spanContext->traceState !== '') {
            $carrier[self::TRACESTATE] = $spanContext->traceState;
        }
    }

    #[Override]
    public function extract(array $carrier, ?Context $context = null): Context
    {
        $base = $context ?? Context::root();
        $traceparent = $carrier[self::TRACEPARENT] ?? null;

        if ($traceparent === null || preg_match(self::TRACEPARENT_PATTERN, $traceparent, $matches) !== 1) {
            return $base;
        }

        $spanContext = new SpanContext(
            traceId: $matches[1],
            spanId: $matches[2],
            traceFlags: (int) hexdec($matches[3]),
            remote: true,
            traceState: $carrier[self::TRACESTATE] ?? '',
        );

        if (!$spanContext->isValid()) {
            return $base;
        }

        return $base->withSpanContext($spanContext);
    }
}
