<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tracing;

use Monadial\Nexus\Symfony\Actor\EnvelopeContext;
use Monadial\Nexus\Symfony\Coroutine\CoroutineContextInterface;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Override;

final class NexusMonologProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CoroutineContextInterface $context,
        private readonly EnvelopeContext $envelopeContext,
    ) {}

    #[Override]
    public function __invoke(LogRecord $record): LogRecord
    {
        $ctx = $this->context->current();

        if (isset($ctx['nexus.request_id'])) {
            return $record->with(extra: [
                ...$record->extra,
                'correlation_id' => $ctx['nexus.correlation_id'],
                'request_id'     => $ctx['nexus.request_id'],
            ]);
        }

        $envelope = $this->envelopeContext->current();

        if ($envelope !== null) {
            return $record->with(extra: [
                ...$record->extra,
                'causation_id'   => $envelope->causationId,
                'correlation_id' => $envelope->correlationId,
                'request_id'     => $envelope->requestId,
            ]);
        }

        return $record;
    }
}
