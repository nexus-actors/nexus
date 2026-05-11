<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Middleware\EnvelopePipeline;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Messaging\Bus\CommandBus;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Marker\Accepted;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
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
 * Synchronous command bus. Implements the canonical CommandBus interface
 * directly (both `dispatchCommand` and `tryDispatch` per H2 — no Rich*
 * extension needed since messaging upstream has `tryDispatch` on
 * canonical).
 *
 * `tryDispatch` propagates `BusInvariantException` per H5 (boot-time
 * misconfiguration is not a domain failure) and lifts every other
 * `Throwable` to `Either::left` — including `RetryBudgetExhaustedException`
 * (runtime-retryable, NOT a boot invariant).
 *
 * The pipeline is pre-assembled by `BusBuilder` at boot. All bus-internal
 * collaborators (logger, metrics, validator, decider, idempotency store,
 * locator, outbox, backoff) are baked into the pipeline closure. The
 * `ClockInterface` is held here to construct fresh root `MessageMetadata`
 * when domain code calls `dispatchCommand` with a raw `Command`.
 */
final class SyncCommandBus implements CommandBus, EnvelopedCommandBus
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly HandlerAttributeIndex $index,
        private readonly EnvelopePipeline $pipeline,
        private readonly Profile $profile,
        private readonly ClockInterface $clock,
    ) {}

    #[Override]
    public function dispatchCommand(Command $command): void
    {
        $this->tryDispatch($command)->getOrCall(static fn(Throwable $e) => throw $e);
    }

    /**
     * @psalm-suppress InvalidArgument
     *   `EnvelopePipeline::dispatch` takes `Envelope<object>`; the local
     *   `Envelope<Command>` is a narrower subtype and accepted at runtime.
     */
    #[Override]
    public function tryDispatch(Command $command): Either
    {
        $envelope = new Envelope($command, MessageMetadata::root($this->clock));

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
    public function dispatchEnveloped(Envelope $envelope): void
    {
        $this->pipeline->dispatch($envelope);
    }
}
