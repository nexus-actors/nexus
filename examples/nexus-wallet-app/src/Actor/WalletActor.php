<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Example\Wallet\Domain\Command\Deposit;
use Monadial\Nexus\Example\Wallet\Domain\Command\GetBalance;
use Monadial\Nexus\Example\Wallet\Domain\Command\Withdraw;
use Monadial\Nexus\Example\Wallet\Domain\Event\MoneyDeposited;
use Monadial\Nexus\Example\Wallet\Domain\Event\MoneyWithdrawn;
use Monadial\Nexus\Example\Wallet\Domain\Event\WalletOpened;
use Monadial\Nexus\Example\Wallet\Domain\Money;
use Monadial\Nexus\Example\Wallet\Domain\Reply\BalanceSnapshot;
use Monadial\Nexus\Example\Wallet\Domain\Reply\DepositResult;
use Monadial\Nexus\Example\Wallet\Domain\Reply\WithdrawResult;
use Monadial\Nexus\Example\Wallet\Domain\State\WalletState;
use Monadial\Nexus\Persistence\Event\EventStore;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\PersistenceId;

/**
 * Per-owner event-sourced wallet.
 *
 * Command/event split:
 *   - Commands carry intent ("Deposit 1000").
 *   - Events carry decisions ("MoneyDeposited 1000") and are the only
 *     thing the store persists.
 *
 * Reply addressing: commands arrive via `ask()`, which stamps the
 * temporary reply ref onto the envelope. The handler captures it via
 * `$ctx->sender()` at the top of the closure so the value is stable
 * inside `thenRun` (which fires after persistence completes — by which
 * point the actor may be processing the next message).
 *
 * Recovery is automatic: on actor (re)start, the persistence engine
 * loads the event log and folds it through `eventHandler` to rebuild
 * the latest WalletState before the first command is processed.
 *
 * For production, swap InMemoryEventStore for DbalEventStore in
 * Bootstrap — the actor code does not change.
 */
final readonly class WalletActor
{
    public static function behavior(string $ownerId, EventStore $store): Behavior
    {
        $persistenceId = PersistenceId::of('Wallet', $ownerId);

        $commandHandler = static function (
            WalletState $state,
            ActorContext $ctx,
            object $command,
        ) use ($ownerId): Effect {
            // Capture sender NOW — by the time `thenRun` fires the actor
            // may have moved on and `$ctx->sender()` will be wrong.
            $sender = $ctx->sender();
            $openIfNeeded = $state->opened
                ? []
                : [new WalletOpened($ownerId)];

            return match (true) {
                $command instanceof Deposit => Effect::persist(
                    ...[...$openIfNeeded, new MoneyDeposited($command->amount->cents)],
                )->thenRun(static function (WalletState $newState) use ($sender): void {
                    $sender?->tell(new DepositResult(
                        accepted: true,
                        balanceCents: $newState->balance->cents,
                    ));
                }),

                $command instanceof Withdraw => $state->balance->isLessThan($command->amount)
                    ? Effect::none()->thenRun(static function () use ($sender, $state): void {
                        $sender?->tell(new WithdrawResult(
                            accepted: false,
                            balanceCents: $state->balance->cents,
                            rejectionReason: 'insufficient funds',
                        ));
                    })
                    : Effect::persist(
                        ...[...$openIfNeeded, new MoneyWithdrawn($command->amount->cents)],
                    )->thenRun(static function (WalletState $newState) use ($sender): void {
                        $sender?->tell(new WithdrawResult(
                            accepted: true,
                            balanceCents: $newState->balance->cents,
                        ));
                    }),

                $command instanceof GetBalance => Effect::none()->thenRun(
                    static function () use ($sender, $state): void {
                        $sender?->tell(new BalanceSnapshot($state->balance->cents));
                    },
                ),

                default => Effect::unhandled(),
            };
        };

        $eventHandler = static fn (WalletState $state, object $event): WalletState => match (true) {
            $event instanceof WalletOpened => $state->open(),
            $event instanceof MoneyDeposited => $state->deposited(new Money($event->amountCents)),
            $event instanceof MoneyWithdrawn => $state->withdrew(new Money($event->amountCents)),
            default => $state,
        };

        return EventSourcedBehavior::create(
            $persistenceId,
            WalletState::empty(),
            $commandHandler,
            $eventHandler,
        )
            ->withEventStore($store)
            ->toBehavior();
    }
}
