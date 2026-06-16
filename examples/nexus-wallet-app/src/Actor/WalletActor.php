<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Wallet\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
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
 * Two halves of the actor's contract live in this class:
 *
 *   - `dispatch()` routes incoming commands to a per-command handler
 *     and produces an `Effect` (persist + side effects, reply, none).
 *   - `applyEvent()` folds a single event into the current state.
 *
 * Each command gets its own private method so the command's logic
 * stays in one place — no nested ternaries, no inline match arms
 * stretching across the file.
 *
 * Recovery is automatic: on actor (re)start, the persistence engine
 * loads the event log and folds it through `applyEvent` to rebuild
 * the latest WalletState before the first command is processed.
 *
 * For production, swap InMemoryEventStore for DbalEventStore in the
 * bootstrap — the actor code does not change.
 */
final readonly class WalletActor
{
    public static function behavior(string $ownerId, EventStore $store): Behavior
    {
        return EventSourcedBehavior::create(
            PersistenceId::of('Wallet', $ownerId),
            WalletState::empty(),
            static fn(WalletState $state, ActorContext $ctx, object $command): Effect => self::dispatch(
                $state,
                $ctx,
                $command,
                $ownerId,
            ),
            static fn(WalletState $state, object $event): WalletState => self::applyEvent($state, $event),
        )
            ->withEventStore($store)
            ->toBehavior();
    }

    private static function dispatch(WalletState $state, ActorContext $ctx, object $command, string $ownerId): Effect
    {
        $sender = $ctx->sender();

        return match (true) {
            $command instanceof Deposit    => self::onDeposit($state, $command, $sender, $ownerId),
            $command instanceof Withdraw   => self::onWithdraw($state, $command, $sender, $ownerId),
            $command instanceof GetBalance => self::onGetBalance($state, $sender),
            default                        => Effect::unhandled(),
        };
    }

    private static function onDeposit(WalletState $state, Deposit $command, ?ActorRef $sender, string $ownerId): Effect
    {
        $events = [
            ...self::openIfFirstInteraction($state, $ownerId),
            new MoneyDeposited($command->amount->cents),
        ];

        return Effect::persist(...$events)->thenRun(
            static function (WalletState $newState) use ($sender): void {
                $sender?->tell(new DepositResult(
                    accepted: true,
                    balanceCents: $newState->balance->cents,
                ));
            },
        );
    }

    private static function onWithdraw(
        WalletState $state,
        Withdraw $command,
        ?ActorRef $sender,
        string $ownerId,
    ): Effect {
        if ($state->balance->isLessThan($command->amount)) {
            return self::rejectWithdraw($state, $sender, 'insufficient funds');
        }

        $events = [
            ...self::openIfFirstInteraction($state, $ownerId),
            new MoneyWithdrawn($command->amount->cents),
        ];

        return Effect::persist(...$events)->thenRun(
            static function (WalletState $newState) use ($sender): void {
                $sender?->tell(new WithdrawResult(
                    accepted: true,
                    balanceCents: $newState->balance->cents,
                ));
            },
        );
    }

    private static function onGetBalance(WalletState $state, ?ActorRef $sender): Effect
    {
        if ($sender === null) {
            return Effect::none();
        }

        return Effect::reply($sender, new BalanceSnapshot($state->balance->cents));
    }

    private static function rejectWithdraw(WalletState $state, ?ActorRef $sender, string $reason): Effect
    {
        if ($sender === null) {
            return Effect::none();
        }

        return Effect::reply($sender, new WithdrawResult(
            accepted: false,
            balanceCents: $state->balance->cents,
            rejectionReason: $reason,
        ));
    }

    /**
     * The wallet may not be touched yet — emit WalletOpened on the
     * first command so the event log distinguishes "never used" from
     * "zero balance after activity".
     *
     * @return list<object>
     */
    private static function openIfFirstInteraction(WalletState $state, string $ownerId): array
    {
        if ($state->opened) {
            return [];
        }

        return [new WalletOpened($ownerId)];
    }

    private static function applyEvent(WalletState $state, object $event): WalletState
    {
        return match (true) {
            $event instanceof WalletOpened   => $state->open(),
            $event instanceof MoneyDeposited => $state->deposited(new Money($event->amountCents)),
            $event instanceof MoneyWithdrawn => $state->withdrew(new Money($event->amountCents)),
            default                          => $state,
        };
    }
}
