<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Context;

use Monadial\Nexus\Observability\Trace\SpanContext;

/**
 * @psalm-api
 * @psalm-immutable
 *
 * Minimal propagation context: the active {@see SpanContext} plus {@see Baggage}.
 */
final readonly class Context
{
    public function __construct(
        public SpanContext $spanContext,
        public Baggage $baggage,
    ) {}

    /** The empty root context (no valid span, no baggage). */
    public static function root(): self
    {
        return new self(SpanContext::invalid(), Baggage::empty());
    }

    public static function fromSpanContext(SpanContext $spanContext): self
    {
        return new self($spanContext, Baggage::empty());
    }

    public function withSpanContext(SpanContext $spanContext): self
    {
        return new self($spanContext, $this->baggage);
    }

    public function withBaggage(Baggage $baggage): self
    {
        return new self($this->spanContext, $baggage);
    }
}
