<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Actor;

use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Symfony\Coroutine\CoroutineContext;

final class EnvelopeContext
{
    private const string CONTEXT_KEY = '__nexus_envelope__';

    public function __construct(private readonly CoroutineContext $context) {}

    public function set(Envelope $envelope): void
    {
        $this->context->current()[self::CONTEXT_KEY] = $envelope;
    }

    public function current(): ?Envelope
    {
        /** @var Envelope|null */
        return $this->context->current()[self::CONTEXT_KEY] ?? null;
    }

    public function clear(): void
    {
        unset($this->context->current()[self::CONTEXT_KEY]);
    }
}
