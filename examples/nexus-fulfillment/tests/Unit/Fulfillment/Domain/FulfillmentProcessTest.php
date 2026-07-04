<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Tests\Unit\Fulfillment\Domain;

use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentCompensated;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentCompleted;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\FulfillmentStarted;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\ReservationConfirmed;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\Event\ReservationFailed;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\FulfillmentPhase;
use Monadial\Nexus\Example\Fulfillment\Fulfillment\Domain\FulfillmentProcess;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReservationRejected;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Inventory\StockReserved;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Contracts\Orders\OrderPlaced;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Money;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderId;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\OrderLine;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Quantity;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\Sku;
use Monadial\Nexus\Example\Fulfillment\SharedKernel\TenantId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Row → test mapping:
 *
 *  D1.  start(lines) on fresh process (Reserving, pending=[])      → [FulfillmentStarted]
 *  D2.  start(lines) on already-started (pending non-empty)        → [] (duplicate)
 *  D3.  start(lines) on Completed                                  → [] (terminal)
 *  D4.  start(lines) on Compensated                                → [] (terminal)
 *  D5.  confirmReservation(sku) for pending sku, not last          → [ReservationConfirmed]
 *  D6.  confirmReservation(sku) for the LAST pending sku           → [ReservationConfirmed, FulfillmentCompleted]
 *  D7.  confirmReservation(sku) for non-pending sku (late/dup)     → []
 *  D8.  confirmReservation(sku) on Completed                       → []
 *  D9.  confirmReservation(sku) on Compensated                     → []
 *  D10. rejectReservation(sku, reason) for pending sku, Reserving  → [ReservationFailed, FulfillmentCompensated]
 *  D11. rejectReservation(sku, reason) for non-pending sku         → []
 *  D12. rejectReservation(sku, reason) on Completed                → []
 *  D13. rejectReservation(sku, reason) on Compensated              → []
 *  A1.  apply FulfillmentStarted fills pending from lines
 *  A2.  apply ReservationConfirmed moves sku from pending to confirmed
 *  A3.  apply ReservationFailed is no-op
 *  A4.  apply FulfillmentCompleted sets phase to Completed
 *  A5.  apply FulfillmentCompensated sets phase to Compensated; confirmed survives intact
 *  A6.  apply unknown event is no-op
 *  A7.  no double-apply: start() records but does not apply; state stays pre-command
 */
#[CoversClass(FulfillmentProcess::class)]
final class FulfillmentProcessTest extends TestCase
{
    private TenantId $tenant;
    private OrderId $orderId;
    private Sku $skuA;
    private Sku $skuB;

    protected function setUp(): void
    {
        $this->tenant = TenantId::fromString('acme');
        $this->orderId = OrderId::generate();
        $this->skuA = Sku::fromString('SKU-AAA');
        $this->skuB = Sku::fromString('SKU-BBB');
    }

    /** @return non-empty-list<OrderLine> */
    private function twoLines(): array
    {
        return [
            new OrderLine($this->skuA, Quantity::of(2), new Money(1000, 'EUR')),
            new OrderLine($this->skuB, Quantity::of(3), new Money(2000, 'EUR')),
        ];
    }

    // D1 — start on fresh process → FulfillmentStarted
    #[Test]
    public function startOnFreshProcessRecordsFulfillmentStarted(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->start(new OrderPlaced($this->tenant, $this->orderId, $this->twoLines(), new Money(3000, 'EUR')));
        $events = $process->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(FulfillmentStarted::class, $events[0]);
        self::assertCount(2, $events[0]->lines);
    }

    // D2 — start on already-started (pending non-empty) → [] (duplicate)
    #[Test]
    public function startOnAlreadyStartedProcessRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));

        $process->start(new OrderPlaced($this->tenant, $this->orderId, $this->twoLines(), new Money(3000, 'EUR')));

        self::assertSame([], $process->releaseEvents());
    }

    // D3 — start on Completed → [] (terminal)
    #[Test]
    public function startOnCompletedProcessRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));
        $process->apply(new ReservationConfirmed($this->tenant, $this->orderId, $this->skuA));
        $process->apply(new ReservationConfirmed($this->tenant, $this->orderId, $this->skuB));
        $process->apply(new FulfillmentCompleted($this->tenant, $this->orderId));

        $process->start(new OrderPlaced($this->tenant, $this->orderId, $this->twoLines(), new Money(3000, 'EUR')));

        self::assertSame([], $process->releaseEvents());
    }

    // D4 — start on Compensated → [] (terminal)
    #[Test]
    public function startOnCompensatedProcessRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));
        $process->apply(new ReservationFailed($this->tenant, $this->orderId, $this->skuA, 'insufficient stock'));
        $process->apply(new FulfillmentCompensated($this->tenant, $this->orderId, 'insufficient stock: SKU-AAA'));

        $process->start(new OrderPlaced($this->tenant, $this->orderId, $this->twoLines(), new Money(3000, 'EUR')));

        self::assertSame([], $process->releaseEvents());
    }

    // D5 — confirmReservation for pending sku (not last) → [ReservationConfirmed]
    #[Test]
    public function confirmReservationForPendingSkuNotLastRecordsReservationConfirmed(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));

        $process->confirmReservation(new StockReserved($this->tenant, $this->skuA, $this->orderId, Quantity::of(1)));
        $events = $process->releaseEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(ReservationConfirmed::class, $events[0]);
        self::assertSame($this->skuA->value, $events[0]->sku->value);
    }

    // D6 — confirmReservation for the LAST pending sku → [ReservationConfirmed, FulfillmentCompleted]
    #[Test]
    public function confirmReservationForLastSkuRecordsConfirmedAndCompleted(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $lines = [new OrderLine($this->skuA, Quantity::of(2), new Money(1000, 'EUR'))];
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $lines));

        $process->confirmReservation(new StockReserved($this->tenant, $this->skuA, $this->orderId, Quantity::of(1)));
        $events = $process->releaseEvents();

        self::assertCount(2, $events);
        self::assertInstanceOf(ReservationConfirmed::class, $events[0]);
        self::assertInstanceOf(FulfillmentCompleted::class, $events[1]);
        self::assertSame($this->skuA->value, $events[0]->sku->value);
    }

    // D7 — confirmReservation for non-pending sku (late/duplicate) → []
    #[Test]
    public function confirmReservationForNonPendingSkuRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));

        // skuB is pending but skuA is confirmed already
        $process->apply(new ReservationConfirmed($this->tenant, $this->orderId, $this->skuA));

        // Confirming skuA again (no longer in pending) → no-op
        $process->confirmReservation(new StockReserved($this->tenant, $this->skuA, $this->orderId, Quantity::of(1)));

        self::assertSame([], $process->releaseEvents());
    }

    // D8 — confirmReservation on Completed → []
    #[Test]
    public function confirmReservationOnCompletedRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $lines = [new OrderLine($this->skuA, Quantity::of(2), new Money(1000, 'EUR'))];
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $lines));
        $process->apply(new ReservationConfirmed($this->tenant, $this->orderId, $this->skuA));
        $process->apply(new FulfillmentCompleted($this->tenant, $this->orderId));

        $process->confirmReservation(new StockReserved($this->tenant, $this->skuA, $this->orderId, Quantity::of(1)));

        self::assertSame([], $process->releaseEvents());
    }

    // D9 — confirmReservation on Compensated → []
    #[Test]
    public function confirmReservationOnCompensatedRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));
        $process->apply(new ReservationFailed($this->tenant, $this->orderId, $this->skuA, 'insufficient stock'));
        $process->apply(new FulfillmentCompensated($this->tenant, $this->orderId, 'insufficient stock: SKU-AAA'));

        $process->confirmReservation(new StockReserved($this->tenant, $this->skuB, $this->orderId, Quantity::of(1)));

        self::assertSame([], $process->releaseEvents());
    }

    // D10 — rejectReservation for pending sku on Reserving → [ReservationFailed, FulfillmentCompensated]
    #[Test]
    public function rejectReservationForPendingSkuRecordsFailedAndCompensated(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));

        $process->rejectReservation(new StockReservationRejected($this->tenant, $this->skuA, $this->orderId, Quantity::of(1), 0, 'insufficient stock'));
        $events = $process->releaseEvents();

        self::assertCount(2, $events);
        self::assertInstanceOf(ReservationFailed::class, $events[0]);
        self::assertInstanceOf(FulfillmentCompensated::class, $events[1]);
        self::assertSame($this->skuA->value, $events[0]->sku->value);
        self::assertStringContainsString('insufficient stock', $events[1]->reason);
        self::assertStringContainsString($this->skuA->value, $events[1]->reason);
    }

    // D11 — rejectReservation for non-pending sku → []
    #[Test]
    public function rejectReservationForNonPendingSkuRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));
        $process->apply(new ReservationConfirmed($this->tenant, $this->orderId, $this->skuA));

        // skuA already confirmed, not pending → no-op
        $process->rejectReservation(new StockReservationRejected($this->tenant, $this->skuA, $this->orderId, Quantity::of(1), 0, 'insufficient stock'));

        self::assertSame([], $process->releaseEvents());
    }

    // D12 — rejectReservation on Completed → []
    #[Test]
    public function rejectReservationOnCompletedRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $lines = [new OrderLine($this->skuA, Quantity::of(2), new Money(1000, 'EUR'))];
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $lines));
        $process->apply(new ReservationConfirmed($this->tenant, $this->orderId, $this->skuA));
        $process->apply(new FulfillmentCompleted($this->tenant, $this->orderId));

        $process->rejectReservation(new StockReservationRejected($this->tenant, $this->skuA, $this->orderId, Quantity::of(1), 0, 'insufficient stock'));

        self::assertSame([], $process->releaseEvents());
    }

    // D13 — rejectReservation on Compensated → []
    #[Test]
    public function rejectReservationOnCompensatedRecordsNothing(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));
        $process->apply(new ReservationFailed($this->tenant, $this->orderId, $this->skuA, 'insufficient stock'));
        $process->apply(new FulfillmentCompensated($this->tenant, $this->orderId, 'insufficient stock: SKU-AAA'));

        $process->rejectReservation(new StockReservationRejected($this->tenant, $this->skuB, $this->orderId, Quantity::of(1), 0, 'insufficient stock'));

        self::assertSame([], $process->releaseEvents());
    }

    // A1 — apply FulfillmentStarted fills pending from lines
    #[Test]
    public function applyFulfillmentStartedFillsPendingFromLines(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));

        self::assertSame(FulfillmentPhase::Reserving, $process->phase);
        self::assertArrayHasKey($this->skuA->value, $process->pending);
        self::assertArrayHasKey($this->skuB->value, $process->pending);
        self::assertSame(2, $process->pending[$this->skuA->value]);
        self::assertSame(3, $process->pending[$this->skuB->value]);
        self::assertSame([], $process->confirmed);
    }

    // A2 — apply ReservationConfirmed moves sku from pending to confirmed
    #[Test]
    public function applyReservationConfirmedMovesPendingToConfirmed(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));
        $process->apply(new ReservationConfirmed($this->tenant, $this->orderId, $this->skuA));

        self::assertArrayNotHasKey($this->skuA->value, $process->pending);
        self::assertArrayHasKey($this->skuB->value, $process->pending);
        self::assertContains($this->skuA->value, $process->confirmed);
        self::assertCount(1, $process->confirmed);
    }

    // A3 — apply ReservationFailed is no-op
    #[Test]
    public function applyReservationFailedIsNoOp(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));

        $pendingBefore = $process->pending;
        $confirmedBefore = $process->confirmed;

        $process->apply(new ReservationFailed($this->tenant, $this->orderId, $this->skuA, 'insufficient stock'));

        self::assertSame($pendingBefore, $process->pending);
        self::assertSame($confirmedBefore, $process->confirmed);
        self::assertSame(FulfillmentPhase::Reserving, $process->phase);
    }

    // A4 — apply FulfillmentCompleted sets phase to Completed
    #[Test]
    public function applyFulfillmentCompletedSetsPhaseToCompleted(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentCompleted($this->tenant, $this->orderId));

        self::assertSame(FulfillmentPhase::Completed, $process->phase);
    }

    // A5 — apply FulfillmentCompensated sets phase to Compensated; confirmed survives intact
    #[Test]
    public function applyFulfillmentCompensatedSetsPhaseAndKeepsConfirmed(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new FulfillmentStarted($this->tenant, $this->orderId, $this->twoLines()));
        $process->apply(new ReservationConfirmed($this->tenant, $this->orderId, $this->skuA));

        // skuA is now confirmed; skuB is still pending
        $process->apply(new FulfillmentCompensated($this->tenant, $this->orderId, 'insufficient stock: SKU-BBB'));

        self::assertSame(FulfillmentPhase::Compensated, $process->phase);
        // confirmed set is still intact — thenRun needs it for ReleaseReservation loop
        self::assertContains($this->skuA->value, $process->confirmed);
        self::assertCount(1, $process->confirmed);
    }

    // A6 — apply unknown event is no-op
    #[Test]
    public function applyUnknownEventIsNoOp(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->apply(new stdClass());

        self::assertSame(FulfillmentPhase::Reserving, $process->phase);
        self::assertSame([], $process->pending);
        self::assertSame([], $process->confirmed);
    }

    // A7 — no double-apply: start() records but does not apply; state stays pre-command
    #[Test]
    public function startRecordsEventWithoutApplyingItSoStateRemainsPreCommand(): void
    {
        $process = FulfillmentProcess::empty($this->tenant, $this->orderId);
        $process->start(new OrderPlaced($this->tenant, $this->orderId, $this->twoLines(), new Money(3000, 'EUR')));

        // State is STILL fresh (pending=[]) — record() must not call apply()
        self::assertSame([], $process->pending);
        self::assertSame(FulfillmentPhase::Reserving, $process->phase);

        $events = $process->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(FulfillmentStarted::class, $events[0]);

        // Now simulate engine fold: apply the event
        $process->apply($events[0]);
        self::assertCount(2, $process->pending);
    }
}
