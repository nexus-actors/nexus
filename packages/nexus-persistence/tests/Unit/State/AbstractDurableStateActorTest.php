<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\State;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Core\Actor\ActorCell;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Supervision\SupervisionStrategy;
use Monadial\Nexus\Core\Tests\Support\TestMailbox;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\AbstractDurableStateActor;
use Monadial\Nexus\Persistence\State\DurableEffect;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

// --- Test message and state classes ---

final readonly class WalletState
{
    public function __construct(public int $balance = 0) {}
}

final readonly class Deposit
{
    public function __construct(public int $amount) {}
}

final readonly class InspectWallet {}

// --- Concrete test actor ---

final class TestWalletActor extends AbstractDurableStateActor
{
    public function persistenceId(): PersistenceId
    {
        return PersistenceId::of('Wallet', 'wallet-1');
    }

    public function emptyState(): object
    {
        return new WalletState(0);
    }

    public function handleCommand(object $state, ActorContext $ctx, object $command): DurableEffect
    {
        if ($command instanceof Deposit) {
            return DurableEffect::persist(new WalletState($state->balance + $command->amount));
        }

        return DurableEffect::none();
    }
}

#[CoversClass(AbstractDurableStateActor::class)]
final class AbstractDurableStateActorTest extends TestCase
{
    private InMemoryDurableStateStore $stateStore;
    private TestWalletActor $actor;

    protected function setUp(): void
    {
        $this->stateStore = new InMemoryDurableStateStore();
        $this->actor = new TestWalletActor($this->stateStore);
    }

    // ========================================================================
    // Test 1: toBehavior() returns a Behavior instance
    // ========================================================================

    #[Test]
    public function toBehaviorReturnsBehaviorInstance(): void
    {
        $behavior = $this->actor->toBehavior();

        self::assertInstanceOf(Behavior::class, $behavior);
    }

    // ========================================================================
    // Test 2: toProps() returns a Props instance
    // ========================================================================

    #[Test]
    public function toPropsReturnsPropsInstance(): void
    {
        $props = $this->actor->toProps();

        self::assertInstanceOf(Props::class, $props);
    }

    // ========================================================================
    // Test 3: toProps() produces Props that contain the behavior
    // ========================================================================

    #[Test]
    public function toPropsContainsBehavior(): void
    {
        $props = $this->actor->toProps();

        self::assertInstanceOf(Props::class, $props);
        self::assertInstanceOf(Behavior::class, $props->behavior);
    }

    // ========================================================================
    // Test 4: Full lifecycle — create, get behavior, spawn, send commands,
    //         verify state persisted in InMemoryDurableStateStore
    // ========================================================================

    #[Test]
    public function fullLifecycleSendCommandsAndVerifyStatePersisted(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $actor = new TestWalletActor($stateStore);

        $behavior = $actor->toBehavior();

        $runtime = new TestRuntime();
        $deadLetters = new DeadLetterRef();
        $logger = new NullLogger();

        $cell = $this->createCell($behavior, $runtime, $deadLetters, $logger);
        $cell->start();

        // Send 3 Deposit commands
        $cell->processMessage($this->envelope(new Deposit(10)));
        $cell->processMessage($this->envelope(new Deposit(20)));
        $cell->processMessage($this->envelope(new Deposit(30)));

        // Verify state was persisted
        $persistenceId = PersistenceId::of('Wallet', 'wallet-1');
        $envelope = $stateStore->get($persistenceId);

        self::assertNotNull($envelope);
        self::assertSame(3, $envelope->version);
        self::assertInstanceOf(WalletState::class, $envelope->state);
        self::assertSame(60, $envelope->state->balance);
    }

    // ========================================================================
    // Test 5: Full lifecycle verifies state is correctly updated
    // ========================================================================

    #[Test]
    public function fullLifecycleStateIsUpdatedAfterCommands(): void
    {
        $stateStore = new InMemoryDurableStateStore();
        $stateCapture = null;

        // Create a custom actor that captures state on InspectWallet command
        $actor = new class ($stateStore, $stateCapture) extends AbstractDurableStateActor {
            /** @param mixed $stateCapture */
            public function __construct(
                InMemoryDurableStateStore $stateStore,
                private mixed &$stateCapture,
            ) {
                parent::__construct($stateStore);
            }

            public function persistenceId(): PersistenceId
            {
                return PersistenceId::of('Wallet', 'wallet-2');
            }

            public function emptyState(): object
            {
                return new WalletState(0);
            }

            public function handleCommand(object $state, ActorContext $ctx, object $command): DurableEffect
            {
                if ($command instanceof Deposit) {
                    return DurableEffect::persist(new WalletState($state->balance + $command->amount));
                }
                if ($command instanceof InspectWallet) {
                    $this->stateCapture = $state;

                    return DurableEffect::none();
                }

                return DurableEffect::none();
            }
        };

        $behavior = $actor->toBehavior();

        $runtime = new TestRuntime();
        $deadLetters = new DeadLetterRef();
        $logger = new NullLogger();

        $cell = $this->createCell($behavior, $runtime, $deadLetters, $logger);
        $cell->start();

        $cell->processMessage($this->envelope(new Deposit(10)));
        $cell->processMessage($this->envelope(new Deposit(20)));
        $cell->processMessage($this->envelope(new Deposit(30)));

        // Inspect state to capture it
        $cell->processMessage($this->envelope(new InspectWallet()));

        self::assertNotNull($stateCapture);
        self::assertInstanceOf(WalletState::class, $stateCapture);
        self::assertSame(60, $stateCapture->balance);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createCell(
        Behavior $behavior,
        TestRuntime $runtime,
        DeadLetterRef $deadLetters,
        NullLogger $logger,
        string $path = '/user/test',
    ): ActorCell {
        $actorPath = ActorPath::fromString($path);
        $mailbox = TestMailbox::unbounded();

        /** @var Option<ActorRef<object>> $noParent */
        $noParent = Option::none();

        return new ActorCell(
            $behavior,
            $actorPath,
            $mailbox,
            $runtime,
            $noParent,
            SupervisionStrategy::oneForOne(),
            $runtime->clock(),
            $logger,
            $deadLetters,
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
