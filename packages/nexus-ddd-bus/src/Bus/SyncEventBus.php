<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Middleware\EnvelopePipeline;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Core\Entity\DomainEvent;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EventBus;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Override;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * @psalm-api
 * @psalm-suppress UnusedClass — wired by the composition root / Phase 15 smoke tests.
 * @psalm-suppress UnusedProperty — `$registry`, `$index`, `$profile` are
 *   locked constructor inputs per plan H10/H13. The pipeline already encodes
 *   the routing decision made at boot; these fields surface via Phase 14 CLI
 *   (`RoutesShowCommand`) and Phase 17 fitness tests.
 *
 * Synchronous event bus. Implements the canonical `EventBus` (both
 * `publishEvent` and `tryPublish` per H2) and `EnvelopedEventBus`. Fan-out
 * to N subscribers happens inside the pipeline's invocation middleware
 * which the composition root assembled; for `Profile::Sync`, every
 * subscriber runs in-transaction.
 *
 * `tryPublish` propagates `BusInvariantException` per H5 and lifts every
 * other `Throwable` to `Either::left`.
 */
final class SyncEventBus implements EventBus, EnvelopedEventBus
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly HandlerAttributeIndex $index,
        private readonly EnvelopePipeline $pipeline,
        private readonly Profile $profile,
        private readonly ClockInterface $clock,
    ) {}

    #[Override]
    public function publishEvent(DomainEvent $event): void
    {
        $this->tryPublish($event)->getOrCall(static fn(Throwable $e) => throw $e);
    }

    /**
     * @psalm-suppress InvalidArgument
     *   `EnvelopePipeline::dispatch` takes `Envelope<object>`; the local
     *   `Envelope<DomainEvent>` is a narrower subtype and accepted at runtime.
     */
    #[Override]
    public function tryPublish(DomainEvent $event): Either
    {
        $envelope = new Envelope($event, MessageMetadata::root($this->clock));

        try {
            $this->pipeline->dispatch($envelope);

            /** @psalm-suppress InvalidReturnStatement */
            return Either::right(new Accepted());
        } catch (Throwable $e) {
            if ($e instanceof BusInvariantException) {
                throw $e;
            }

            /** @psalm-suppress InvalidReturnStatement */
            return Either::left($e);
        }
    }

    /** @psalm-suppress InvalidArgument */
    #[Override]
    public function publishEnveloped(Envelope $envelope): void
    {
        $this->pipeline->dispatch($envelope);
    }
}
