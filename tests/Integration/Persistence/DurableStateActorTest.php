<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Persistence;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Persistence\State\AbstractDurableStateActor;
use Monadial\Nexus\Persistence\State\DurableEffect;
use Monadial\Nexus\Persistence\State\DurableStateBehavior;
use Monadial\Nexus\Persistence\State\InMemoryDurableStateStore;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\GetValue;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\SetValue;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ValueReply;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ValueState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DurableStateActorTest extends TestCase
{
    #[Test]
    public function spawnAndPersistState(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ds-functional-test', $runtime);

        $stateStore = new InMemoryDurableStateStore();
        $persistenceId = PersistenceId::of('ValueHolder', 'val-1');

        $behavior = DurableStateBehavior::create(
            $persistenceId,
            new ValueState(),
            static function (ValueState $state, ActorContext $ctx, object $command): DurableEffect {
                if ($command instanceof SetValue) {
                    return DurableEffect::persist(new ValueState($command->value));
                }

                if ($command instanceof GetValue) {
                    return DurableEffect::reply($command->replyTo, new ValueReply($state->value));
                }

                return DurableEffect::none();
            },
        )
            ->withStateStore($stateStore)
            ->toBehavior();

        $ref = $system->spawn(Props::fromBehavior($behavior), 'value-holder');

        // Send SetValue commands (each one overwrites the previous state)
        $ref->tell(new SetValue('first'));
        $ref->tell(new SetValue('second'));
        $ref->tell(new SetValue('final'));

        // Probe actor to capture replies
        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        // Ask for current value
        $ref->tell(new GetValue($probeRef));

        // Schedule shutdown
        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        // Verify reply contains the final value
        self::assertCount(1, $captured);
        self::assertInstanceOf(ValueReply::class, $captured[0]);
        self::assertSame('final', $captured[0]->value);

        // Verify state was persisted in the durable state store
        $envelope = $stateStore->get($persistenceId);
        self::assertNotNull($envelope);
        self::assertInstanceOf(ValueState::class, $envelope->state);
        self::assertSame('final', $envelope->state->value);
        self::assertSame(3, $envelope->revision);
    }

    #[Test]
    public function classBasedDurableStateActor(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('ds-class-test', $runtime);

        $stateStore = new InMemoryDurableStateStore();

        $actor = new class ($stateStore) extends AbstractDurableStateActor {
            public function __construct(InMemoryDurableStateStore $stateStore)
            {
                parent::__construct($stateStore);
            }

            public function persistenceId(): PersistenceId
            {
                return PersistenceId::of('ValueHolder', 'val-class');
            }

            public function emptyState(): object
            {
                return new ValueState();
            }

            public function handleCommand(object $state, ActorContext $ctx, object $command): DurableEffect
            {
                if ($command instanceof SetValue) {
                    return DurableEffect::persist(new ValueState($command->value));
                }

                if ($command instanceof GetValue) {
                    return DurableEffect::reply($command->replyTo, new ValueReply($state->value));
                }

                return DurableEffect::none();
            }
        };

        $ref = $system->spawn($actor->toProps(), 'value-holder');

        $ref->tell(new SetValue('hello'));
        $ref->tell(new SetValue('world'));

        /** @var list<object> $captured */
        $captured = [];

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        $ref->tell(new GetValue($probeRef));

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();

        self::assertCount(1, $captured);
        self::assertInstanceOf(ValueReply::class, $captured[0]);
        self::assertSame('world', $captured[0]->value);

        // Verify state in store
        $persistenceId = PersistenceId::of('ValueHolder', 'val-class');
        $envelope = $stateStore->get($persistenceId);
        self::assertNotNull($envelope);
        self::assertInstanceOf(ValueState::class, $envelope->state);
        self::assertSame('world', $envelope->state->value);
        self::assertSame(2, $envelope->revision);
    }
}
