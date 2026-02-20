<?php
declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Persistence\Dbal\DbalEventStore;
use Monadial\Nexus\Persistence\Dbal\DbalSnapshotStore;
use Monadial\Nexus\Persistence\Dbal\Schema\PersistenceSchemaManager;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EventSourcedBehavior;
use Monadial\Nexus\Persistence\EventSourced\SnapshotStrategy;
use Monadial\Nexus\Persistence\PersistenceId;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\AddItem;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\GetItems;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ItemAdded;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ItemsReply;
use Monadial\Nexus\Tests\Integration\Persistence\Messages\ShoppingCart;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DbalEventSourcedActorTest extends TestCase
{
    private Connection $connection;

    private string $dbPath;

    #[Test]
    public function fullLifecycleWithDbalEventStore(): void
    {
        $persistenceId = PersistenceId::of('ShoppingCart', 'dbal-test-1');
        $eventStore = new DbalEventStore($this->connection);

        // Phase 1: Spawn actor, send commands, verify events in DB
        $captured = [];
        $this->runActorSystem('phase1', $persistenceId, $eventStore, null, static function ($ref, $probeRef): void {
            $ref->tell(new AddItem('apple'));
            $ref->tell(new AddItem('banana'));
            $ref->tell(new GetItems($probeRef));
        }, $captured);

        // Verify reply contains both items
        self::assertCount(1, $captured);
        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['apple', 'banana'], $captured[0]->items);

        // Verify events persisted in the database
        self::assertSame(2, $eventStore->highestSequenceNr($persistenceId));
        $events = iterator_to_array($eventStore->load($persistenceId));
        self::assertCount(2, $events);
        self::assertInstanceOf(ItemAdded::class, $events[0]->event);
        self::assertSame('apple', $events[0]->event->item);
        self::assertSame(1, $events[0]->sequenceNr);
        self::assertInstanceOf(ItemAdded::class, $events[1]->event);
        self::assertSame('banana', $events[1]->event->item);
        self::assertSame(2, $events[1]->sequenceNr);

        // Phase 2: Spawn NEW actor system with SAME persistence ID — should recover
        $captured2 = [];
        $this->runActorSystem('phase2', $persistenceId, $eventStore, null, static function ($ref, $probeRef): void {
            $ref->tell(new GetItems($probeRef));
        }, $captured2);

        // Verify recovered state includes items from phase 1
        self::assertCount(1, $captured2);
        self::assertInstanceOf(ItemsReply::class, $captured2[0]);
        self::assertSame(['apple', 'banana'], $captured2[0]->items);
    }

    #[Test]
    public function fullLifecycleWithSnapshotRecovery(): void
    {
        $persistenceId = PersistenceId::of('ShoppingCart', 'dbal-snap-test');
        $eventStore = new DbalEventStore($this->connection);
        $snapshotStore = new DbalSnapshotStore($this->connection);

        // Phase 1: Add 3 items with snapshot every 2 events
        $captured = [];
        $this->runActorSystem(
            'snap1',
            $persistenceId,
            $eventStore,
            $snapshotStore,
            static function ($ref, $probeRef): void {
                $ref->tell(new AddItem('x'));
                $ref->tell(new AddItem('y'));
                $ref->tell(new AddItem('z'));
                $ref->tell(new GetItems($probeRef));
            },
            $captured,
            SnapshotStrategy::everyN(2),
        );

        self::assertCount(1, $captured);
        self::assertInstanceOf(ItemsReply::class, $captured[0]);
        self::assertSame(['x', 'y', 'z'], $captured[0]->items);

        // Verify snapshot exists at sequenceNr 2 (everyN(2) triggers at seqNr 2)
        $snapshot = $snapshotStore->load($persistenceId);
        self::assertNotNull($snapshot);
        self::assertSame(2, $snapshot->sequenceNr);

        // Verify all 3 events are in the journal
        self::assertSame(3, $eventStore->highestSequenceNr($persistenceId));

        // Phase 2: Recover from snapshot + remaining events
        $captured2 = [];
        $this->runActorSystem(
            'snap2',
            $persistenceId,
            $eventStore,
            $snapshotStore,
            static function ($ref, $probeRef): void {
                $ref->tell(new GetItems($probeRef));
            },
            $captured2,
            SnapshotStrategy::everyN(2),
        );

        self::assertCount(1, $captured2);
        self::assertInstanceOf(ItemsReply::class, $captured2[0]);
        self::assertSame(['x', 'y', 'z'], $captured2[0]->items);
    }

    #[Test]
    public function recoveryAfterAdditionalCommandsPostRestart(): void
    {
        $persistenceId = PersistenceId::of('ShoppingCart', 'dbal-restart-test');
        $eventStore = new DbalEventStore($this->connection);

        // Phase 1: Add initial items
        $captured = [];
        $this->runActorSystem('restart1', $persistenceId, $eventStore, null, static function ($ref, $probeRef): void {
            $ref->tell(new AddItem('milk'));
            $ref->tell(new AddItem('bread'));
        }, $captured);

        self::assertSame(2, $eventStore->highestSequenceNr($persistenceId));

        // Phase 2: Recover and add more items — sequence numbers should continue
        $captured2 = [];
        $this->runActorSystem('restart2', $persistenceId, $eventStore, null, static function ($ref, $probeRef): void {
            $ref->tell(new AddItem('eggs'));
            $ref->tell(new GetItems($probeRef));
        }, $captured2);

        // Verify all items present (recovered + new)
        self::assertCount(1, $captured2);
        self::assertInstanceOf(ItemsReply::class, $captured2[0]);
        self::assertSame(['milk', 'bread', 'eggs'], $captured2[0]->items);

        // Verify sequence numbers are continuous
        self::assertSame(3, $eventStore->highestSequenceNr($persistenceId));
        $events = iterator_to_array($eventStore->load($persistenceId));
        self::assertCount(3, $events);
        self::assertSame(1, $events[0]->sequenceNr);
        self::assertSame(2, $events[1]->sequenceNr);
        self::assertSame(3, $events[2]->sequenceNr);
        self::assertSame('eggs', $events[2]->event->item);
    }

    protected function setUp(): void
    {
        $this->dbPath = '/tmp/nexus_test_' . uniqid() . '.db';

        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->dbPath,
        ]);

        (new PersistenceSchemaManager($this->connection))->createSchema();
    }

    protected function tearDown(): void
    {
        $this->connection->close();

        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    /**
     * @param list<object> $captured
     */
    private function runActorSystem(
        string $name,
        PersistenceId $persistenceId,
        DbalEventStore $eventStore,
        ?DbalSnapshotStore $snapshotStore,
        callable $actions,
        array &$captured,
        ?SnapshotStrategy $strategy = null,
    ): void {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create($name, $runtime);

        $builder = EventSourcedBehavior::create(
            $persistenceId,
            new ShoppingCart(),
            static function (ShoppingCart $state, ActorContext $ctx, object $command): Effect {
                if ($command instanceof AddItem) {
                    return Effect::persist(new ItemAdded($command->item));
                }

                if ($command instanceof GetItems) {
                    return Effect::reply($command->replyTo, new ItemsReply($state->items));
                }

                return Effect::none();
            },
            static function (ShoppingCart $state, object $event): ShoppingCart {
                if ($event instanceof ItemAdded) {
                    return new ShoppingCart([...$state->items, $event->item]);
                }

                return $state;
            },
        )->withEventStore($eventStore);

        if ($snapshotStore !== null) {
            $builder = $builder->withSnapshotStore($snapshotStore);
        }

        if ($strategy !== null) {
            $builder = $builder->withSnapshotStrategy($strategy);
        }

        $behavior = $builder->toBehavior();
        $ref = $system->spawn(Props::fromBehavior($behavior), 'cart');

        /** @var Behavior<object> $probeBehavior */
        $probeBehavior = Behavior::receive(static function (ActorContext $ctx, object $msg) use (&$captured): Behavior {
            $captured[] = $msg;

            return Behavior::same();
        });

        $probeRef = $system->spawn(Props::fromBehavior($probeBehavior), 'probe');

        $actions($ref, $probeRef);

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });

        $system->run();
    }
}
