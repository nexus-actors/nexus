<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\State;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\DurableEffect;
use Monadial\Nexus\Persistence\State\DurableStateEngine;
use Monadial\Nexus\Persistence\State\DurableStateEnvelope;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// --- Test message and state classes ---

final readonly class SetBalance
{
    public function __construct(public int $amount) {}
}

final readonly class GetBalance
{
    public function __construct(public ActorRef $replyTo) {}
}

final readonly class BalanceReply
{
    public function __construct(public int $balance) {}
}

final readonly class DurableDoNothing
{
}

final readonly class DurableStopCommand
{
}

final readonly class AccountState
{
    public function __construct(public int $balance = 0) {}
}

#[CoversClass(DurableStateEngine::class)]
final class DurableStateEngineTest extends TestCase
{
    private TestRuntime $runtime;
    private DeadLetterRef $deadLetters;
    private NullLogger $logger;
    private PersistenceId $persistenceId;

    protected function setUp(): void
    {
        $this->runtime = new TestRuntime();
        $this->deadLetters = new DeadLetterRef();
        $this->logger = new NullLogger();
        $this->persistenceId = PersistenceId::of('Account', 'acc-1');
    }

    // ========================================================================
    // Test 1: Recovery from empty state
    // ========================================================================

    #[Test]
    public function recovery_from_empty_state_uses_empty_state(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $recoveredState = null;

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$recoveredState): DurableEffect {
                $recoveredState = $state;

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Send a message to trigger the command handler
        $cell->processMessage($this->envelope(new DurableDoNothing()));

        self::assertNotNull($recoveredState);
        self::assertInstanceOf(AccountState::class, $recoveredState);
        self::assertSame(0, $recoveredState->balance);
    }

    // ========================================================================
    // Test 2: Command processing with persist — state stored in DurableStateStore
    // ========================================================================

    #[Test]
    public function command_processing_persists_state_and_updates_internal_state(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $stateAfterPersist = null;

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$stateAfterPersist): DurableEffect {
                if ($msg instanceof SetBalance) {
                    return DurableEffect::persist(new AccountState($msg->amount));
                }
                if ($msg instanceof DurableDoNothing) {
                    $stateAfterPersist = $state;

                    return DurableEffect::none();
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Set balance
        $cell->processMessage($this->envelope(new SetBalance(100)));

        // Verify state was persisted in the DurableStateStore
        $envelope = $stateStore->get($this->persistenceId);
        self::assertNotNull($envelope);
        self::assertSame(1, $envelope->version);
        self::assertInstanceOf(AccountState::class, $envelope->state);
        self::assertSame(100, $envelope->state->balance);
        self::assertSame(AccountState::class, $envelope->stateType);

        // Verify internal state was updated (send another message to inspect)
        $cell->processMessage($this->envelope(new DurableDoNothing()));
        self::assertNotNull($stateAfterPersist);
        self::assertInstanceOf(AccountState::class, $stateAfterPersist);
        self::assertSame(100, $stateAfterPersist->balance);
    }

    // ========================================================================
    // Test 3: Recovery from existing state — pre-populate store, verify recovery
    // ========================================================================

    #[Test]
    public function recovery_from_existing_state_loads_persisted_state(): void
    {
        $stateStore = new InMemoryDurableStateStore();

        // Pre-populate the state store
        $stateStore->upsert($this->persistenceId, new DurableStateEnvelope(
            persistenceId: $this->persistenceId,
            version: 5,
            state: new AccountState(500),
            stateType: AccountState::class,
            timestamp: new DateTimeImmutable(),
        ));

        $recoveredState = null;

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$recoveredState): DurableEffect {
                $recoveredState = $state;

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Send a message to get the recovered state
        $cell->processMessage($this->envelope(new DurableDoNothing()));

        self::assertNotNull($recoveredState);
        self::assertInstanceOf(AccountState::class, $recoveredState);
        self::assertSame(500, $recoveredState->balance);
    }

    #[Test]
    public function recovery_continues_version_after_existing_state(): void
    {
        $stateStore = new InMemoryDurableStateStore();

        // Pre-populate with version 5
        $stateStore->upsert($this->persistenceId, new DurableStateEnvelope(
            persistenceId: $this->persistenceId,
            version: 5,
            state: new AccountState(500),
            stateType: AccountState::class,
            timestamp: new DateTimeImmutable(),
        ));

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg): DurableEffect {
                if ($msg instanceof SetBalance) {
                    return DurableEffect::persist(new AccountState($msg->amount));
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // New command should produce version=6
        $cell->processMessage($this->envelope(new SetBalance(600)));

        $envelope = $stateStore->get($this->persistenceId);
        self::assertNotNull($envelope);
        self::assertSame(6, $envelope->version);
        self::assertSame(600, $envelope->state->balance);
    }

    // ========================================================================
    // Test 4: DurableEffect::none() leaves state unchanged
    // ========================================================================

    #[Test]
    public function effect_none_leaves_state_unchanged(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $states = [];

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$states): DurableEffect {
                $states[] = $state;
                if ($msg instanceof SetBalance) {
                    return DurableEffect::persist(new AccountState($msg->amount));
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        // Set balance first
        $cell->processMessage($this->envelope(new SetBalance(100)));

        // Send DoNothing (effect::none) -- should not change state
        $cell->processMessage($this->envelope(new DurableDoNothing()));
        $cell->processMessage($this->envelope(new DurableDoNothing()));

        // No new state should be persisted after the first one
        $envelope = $stateStore->get($this->persistenceId);
        self::assertSame(1, $envelope->version); // only one persist

        // All three state observations: first sees empty, second sees 100, third sees 100
        self::assertCount(3, $states);
        self::assertSame(0, $states[0]->balance);
        self::assertSame(100, $states[1]->balance);
        self::assertSame(100, $states[2]->balance);
    }

    // ========================================================================
    // Test 5: DurableEffect::reply() sends reply
    // ========================================================================

    #[Test]
    public function effect_reply_sends_reply_message(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $replyCapture = new DeadLetterRef();

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg): DurableEffect {
                if ($msg instanceof SetBalance) {
                    return DurableEffect::persist(new AccountState($msg->amount));
                }
                if ($msg instanceof GetBalance) {
                    return DurableEffect::reply($msg->replyTo, new BalanceReply($state->balance));
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new SetBalance(100)));
        $cell->processMessage($this->envelope(new SetBalance(200)));

        // Ask for balance using DeadLetterRef as reply target
        $cell->processMessage($this->envelope(new GetBalance($replyCapture)));

        $captured = $replyCapture->captured();
        self::assertCount(1, $captured);
        self::assertInstanceOf(BalanceReply::class, $captured[0]);
        self::assertSame(200, $captured[0]->balance);

        // No new state should be persisted for the reply
        $envelope = $stateStore->get($this->persistenceId);
        self::assertSame(2, $envelope->version); // only the two SetBalance persists
    }

    // ========================================================================
    // Test 6: DurableEffect::stop() stops actor
    // ========================================================================

    #[Test]
    public function effect_stop_stops_the_actor(): void
    {
        $stateStore = new InMemoryDurableStateStore();

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg): DurableEffect {
                if ($msg instanceof DurableStopCommand) {
                    return DurableEffect::stop();
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        self::assertTrue($cell->isAlive());

        $cell->processMessage($this->envelope(new DurableStopCommand()));

        self::assertFalse($cell->isAlive());
    }

    // ========================================================================
    // Test 7: Side effects executed after persist
    // ========================================================================

    #[Test]
    public function side_effects_executed_after_persist(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $sideEffectLog = [];

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$sideEffectLog): DurableEffect {
                if ($msg instanceof SetBalance) {
                    return DurableEffect::persist(new AccountState($msg->amount))
                        ->thenRun(static function (object $newState) use (&$sideEffectLog): void {
                            $sideEffectLog[] = 'after-persist: ' . $newState->balance;
                        });
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new SetBalance(100)));

        self::assertCount(1, $sideEffectLog);
        // Side effect should see the NEW state (after persist)
        self::assertSame('after-persist: 100', $sideEffectLog[0]);

        // Persist another state
        $cell->processMessage($this->envelope(new SetBalance(200)));

        self::assertCount(2, $sideEffectLog);
        self::assertSame('after-persist: 200', $sideEffectLog[1]);
    }

    #[Test]
    public function then_reply_side_effect_sends_reply_with_new_state(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $replyCapture = new DeadLetterRef();

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg) use ($replyCapture): DurableEffect {
                if ($msg instanceof SetBalance) {
                    return DurableEffect::persist(new AccountState($msg->amount))
                        ->thenReply($replyCapture, static function (object $newState): object {
                            return new BalanceReply($newState->balance);
                        });
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new SetBalance(100)));

        $captured = $replyCapture->captured();
        self::assertCount(1, $captured);
        self::assertInstanceOf(BalanceReply::class, $captured[0]);
        self::assertSame(100, $captured[0]->balance);
    }

    // ========================================================================
    // Test 8: Revision increments correctly across multiple persists
    // ========================================================================

    #[Test]
    public function version_increments_correctly_across_multiple_persists(): void
    {
        $stateStore = new InMemoryDurableStateStore();

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg): DurableEffect {
                if ($msg instanceof SetBalance) {
                    return DurableEffect::persist(new AccountState($msg->amount));
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new SetBalance(100)));
        $envelope1 = $stateStore->get($this->persistenceId);
        self::assertSame(1, $envelope1->version);

        $cell->processMessage($this->envelope(new SetBalance(200)));
        $envelope2 = $stateStore->get($this->persistenceId);
        self::assertSame(2, $envelope2->version);

        $cell->processMessage($this->envelope(new SetBalance(300)));
        $envelope3 = $stateStore->get($this->persistenceId);
        self::assertSame(3, $envelope3->version);
        self::assertSame(300, $envelope3->state->balance);
    }

    // ========================================================================
    // Test: DurableEffect::stash() stashes message in context
    // ========================================================================

    #[Test]
    public function effect_stash_stashes_message_in_context(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $stashCalled = false;

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$stashCalled): DurableEffect {
                if ($msg instanceof DurableDoNothing) {
                    $stashCalled = true;

                    return DurableEffect::stash();
                }

                return DurableEffect::none();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new DurableDoNothing()));

        // The stash effect should have triggered the stash
        self::assertTrue($stashCalled);
    }

    // ========================================================================
    // Test: DurableEffect::unhandled() leaves state unchanged
    // ========================================================================

    #[Test]
    public function effect_unhandled_leaves_state_unchanged(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $states = [];

        $behavior = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            static function (object $state, ActorContext $ctx, object $msg) use (&$states): DurableEffect {
                $states[] = $state;
                if ($msg instanceof SetBalance) {
                    return DurableEffect::persist(new AccountState($msg->amount));
                }

                return DurableEffect::unhandled();
            },
            $stateStore,
        );

        $cell = $this->createCell($behavior);
        $cell->start();

        $cell->processMessage($this->envelope(new SetBalance(100)));
        $cell->processMessage($this->envelope(new DurableDoNothing())); // unhandled

        // State should still be 100 after unhandled
        self::assertCount(2, $states);
        self::assertSame(100, $states[1]->balance);
    }

    // ========================================================================
    // Test: Full round-trip — persist, stop, recover
    // ========================================================================

    #[Test]
    public function full_round_trip_persist_stop_recover(): void
    {
        $stateStore = new InMemoryDurableStateStore();

        // Create first actor instance
        $commandHandler = static function (object $state, ActorContext $ctx, object $msg): DurableEffect {
            if ($msg instanceof SetBalance) {
                return DurableEffect::persist(new AccountState($msg->amount));
            }

            return DurableEffect::none();
        };

        $behavior1 = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            $commandHandler,
            $stateStore,
        );

        $cell1 = $this->createCell($behavior1);
        $cell1->start();

        $cell1->processMessage($this->envelope(new SetBalance(100)));
        $cell1->processMessage($this->envelope(new SetBalance(200)));
        $cell1->processMessage($this->envelope(new SetBalance(300)));

        $cell1->initiateStop();

        // Create second actor instance (simulating recovery)
        $recoveredState = null;
        $commandHandler2 = static function (object $state, ActorContext $ctx, object $msg) use (&$recoveredState): DurableEffect {
            $recoveredState = $state;

            return DurableEffect::none();
        };

        $behavior2 = DurableStateEngine::create(
            $this->persistenceId,
            new AccountState(),
            $commandHandler2,
            $stateStore,
        );

        $cell2 = $this->createCell($behavior2, '/user/acc-recovered');
        $cell2->start();

        $cell2->processMessage($this->envelope(new DurableDoNothing(), '/user/acc-recovered'));

        // Should recover the last persisted state (300)
        self::assertNotNull($recoveredState);
        self::assertInstanceOf(AccountState::class, $recoveredState);
        self::assertSame(300, $recoveredState->balance);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createCell(mixed $behavior, string $path = '/user/test'): ActorCell
    {
        $actorPath = ActorPath::fromString($path);
        $mailbox = TestMailbox::unbounded();

        /** @var Option<ActorRef<object>> $noParent */
        $noParent = Option::none();

        return new ActorCell(
            $behavior,
            $actorPath,
            $mailbox,
            $this->runtime,
            $noParent,
            SupervisionStrategy::oneForOne(),
            $this->runtime->clock(),
            $this->logger,
            $this->deadLetters,
        );
    }

    private function envelope(object $message, string $target = '/user/test'): Envelope
    {
        return Envelope::of(
            $message,
            ActorPath::root(),
            ActorPath::fromString($target),
        );
    }
}
