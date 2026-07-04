<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain;

use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentCompensated;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentCompleted;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentStarted;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\ReservationConfirmed;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\ReservationFailed;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;

/**
 * FulfillmentProcess aggregate root. Mutable — apply() mutates state;
 * record() appends events without applying them.
 *
 * CRITICAL — no double-apply: record() appends to $recorded and NEVER calls
 * apply(). The persistence engine calls the event handler closure
 * `fn(FulfillmentProcess $p, object $e): FulfillmentProcess => { $p->apply($e); return $p; }`
 * for each persisted event. Self-applying inside record() would double-apply every event.
 * Behavior methods therefore read PRE-command state — identical semantics to
 * the old SagaRules::decide().
 *
 * At-most-once side-effects: thenRun closures do NOT re-run on replay. A crash
 * between persist and thenRun executing leaves the saga mid-flight until the
 * next relevant event is delivered (at-most-once seam, same as the ContextBus).
 * Journal-backed redelivery resolves this in the broker milestone.
 *
 * confirmed-set design: apply(FulfillmentCompensated) only sets phase —
 * it does NOT clear $confirmed. This lets the thenRun closure read
 * $next->confirmed to issue ReleaseReservation tells after compensation folds.
 *
 * PUBLIC constructor field names are the snapshot shape ('fulfillment.process_state.v1'):
 * Valinor constructs FulfillmentProcess via the public constructor on replay.
 *
 * $recorded is private and excluded from Valinor snapshot serialization by design.
 */
final class FulfillmentProcess
{
    /** @var list<object> */
    private array $recorded = [];

    /**
     * @param array<string, int> $pending  sku->qty map of outstanding reservations
     * @param list<string>       $confirmed sku strings of confirmed reservations
     */
    public function __construct(
        public private(set) TenantId $tenantId,
        public private(set) OrderId $orderId,
        public private(set) FulfillmentPhase $phase,
        public private(set) array $pending,
        public private(set) array $confirmed,
    ) {}

    public static function empty(TenantId $tenantId, OrderId $orderId): self
    {
        return new self($tenantId, $orderId, FulfillmentPhase::Reserving, [], []);
    }

    /**
     * Start the fulfillment process by issuing a reservation for each line.
     * Records FulfillmentStarted only when phase is Reserving AND pending is
     * empty (i.e. the process has not been started yet).
     * Late/duplicate deliveries (pending non-empty or terminal phase) → no-op.
     *
     * @param non-empty-list<OrderLine> $lines
     */
    public function start(array $lines): void
    {
        if ($this->phase !== FulfillmentPhase::Reserving || $this->pending !== []) {
            return;
        }

        $this->record(new FulfillmentStarted($this->tenantId, $this->orderId, $lines));
    }

    /**
     * Confirm that inventory successfully reserved the given SKU.
     * Records ReservationConfirmed (and FulfillmentCompleted in the same drain
     * when this was the last pending SKU). Late/duplicate/terminal → no-op.
     */
    public function confirmReservation(Sku $sku): void
    {
        if ($this->phase !== FulfillmentPhase::Reserving || !isset($this->pending[$sku->value])) {
            return;
        }

        $this->record(new ReservationConfirmed($this->tenantId, $this->orderId, $sku));

        // count($this->pending) === 1 here: this is the only outstanding reservation.
        // After apply(ReservationConfirmed), pending will be empty → process is complete.
        // record() does NOT call apply(), so $this->pending still reflects PRE-command state.
        if (count($this->pending) === 1) {
            $this->record(new FulfillmentCompleted($this->tenantId, $this->orderId));
        }
    }

    /**
     * Handle an inventory rejection for the given SKU.
     * Records ReservationFailed + FulfillmentCompensated in the same drain.
     * Non-pending sku / terminal phase → no-op.
     */
    public function rejectReservation(Sku $sku, string $reason): void
    {
        if ($this->phase !== FulfillmentPhase::Reserving || !isset($this->pending[$sku->value])) {
            return;
        }

        $this->record(new ReservationFailed($this->tenantId, $this->orderId, $sku, $reason));
        $this->record(new FulfillmentCompensated(
            $this->tenantId,
            $this->orderId,
            "insufficient stock: {$sku->value}",
        ));
    }

    /**
     * Drain and return all recorded events. Must be called once per command.
     *
     * @return list<object>
     */
    public function releaseEvents(): array
    {
        $events = $this->recorded;
        $this->recorded = [];

        return $events;
    }

    /**
     * Apply an event — called by the persistence engine's event fold.
     * MUST NOT be called from record() to prevent double-apply.
     */
    public function apply(object $event): void
    {
        match (true) {
            $event instanceof FulfillmentStarted => $this->applyFulfillmentStarted($event),
            $event instanceof ReservationConfirmed => $this->applyReservationConfirmed($event),
            $event instanceof FulfillmentCompleted => $this->phase = FulfillmentPhase::Completed,
            $event instanceof FulfillmentCompensated => $this->phase = FulfillmentPhase::Compensated,
            // ReservationFailed and unknown events are no-ops: state unchanged
            default => null,
        };
    }

    /**
     * Append an event to $recorded — does NOT apply it.
     */
    private function record(object $event): void
    {
        $this->recorded[] = $event;
    }

    private function applyFulfillmentStarted(FulfillmentStarted $event): void
    {
        $pending = [];

        foreach ($event->lines as $line) {
            $pending[$line->sku->value] = $line->quantity->value;
        }

        $this->pending = $pending;
    }

    private function applyReservationConfirmed(ReservationConfirmed $event): void
    {
        $this->confirmed[] = $event->sku->value;
        unset($this->pending[$event->sku->value]);
    }
}
