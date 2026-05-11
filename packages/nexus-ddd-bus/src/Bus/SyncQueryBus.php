<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Bus;

use Fp\Functional\Either\Either;
use Monadial\Nexus\Ddd\Bus\Exception\BusInvariantException;
use Monadial\Nexus\Ddd\Bus\Middleware\MiddlewarePipeline;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\HandlerAttributeIndex;
use Monadial\Nexus\Ddd\Messaging\Bus\EnvelopedQueryBus;
use Monadial\Nexus\Ddd\Messaging\Bus\QueryBus;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Message\Query;
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
 * Synchronous query bus. Implements the canonical `QueryBus` (both
 * `dispatchQuery` and `tryAsk` per H2 — no Rich* extension needed) and
 * `EnvelopedQueryBus`. The pipeline returns the typed query result.
 *
 * No idempotency middleware (queries are inherently idempotent) and no
 * event drain (queries don't emit events) — the canonical pipeline for
 * queries is shorter than the command one, assembled at boot.
 *
 * `tryAsk` propagates `BusInvariantException` per H5 and lifts every
 * other `Throwable` to `Either::left`.
 */
final class SyncQueryBus implements QueryBus, EnvelopedQueryBus
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly HandlerAttributeIndex $index,
        private readonly MiddlewarePipeline $pipeline,
        private readonly Profile $profile,
        private readonly ClockInterface $clock,
    ) {}

    #[Override]
    public function dispatchQuery(Query $query): mixed
    {
        return $this->tryAsk($query)->getOrCall(static fn(Throwable $e) => throw $e);
    }

    #[Override]
    public function tryAsk(Query $query): Either
    {
        $envelope = new Envelope($query, MessageMetadata::root($this->clock));

        try {
            /** @psalm-suppress InvalidReturnStatement, MixedArgument */
            return Either::right($this->pipeline->dispatch($envelope));
        } catch (Throwable $e) {
            if ($e instanceof BusInvariantException) {
                throw $e;
            }

            /** @psalm-suppress InvalidReturnStatement */
            return Either::left($e);
        }
    }

    #[Override]
    public function dispatchEnveloped(Envelope $envelope): mixed
    {
        /** @psalm-suppress MixedReturnStatement */
        return $this->pipeline->dispatch($envelope);
    }
}
