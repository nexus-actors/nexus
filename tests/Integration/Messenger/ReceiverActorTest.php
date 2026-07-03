<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Consumer\ReceiverActor;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Tests\Support\RecordingObservability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Runtime\Mailbox\EnqueueResult;
use Monadial\Nexus\Tests\Integration\Messenger\Messages\Ping;
use Monadial\Nexus\Tests\Integration\Messenger\Support\TogglableBackpressureRef;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(ReceiverActor::class)]
final class ReceiverActorTest extends TestCase
{
    #[Test]
    public function consumesRoutesAndAcksQueuedMessages(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('receiver-happy', $runtime);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new Ping('a')));
        $transport->send(new Envelope(new Ping('b')));

        $received = [];
        $target = $system->spawn(Props::fromBehavior(Behavior::receive(
            static function (ActorContext $ctx, object $msg) use (&$received): Behavior {
                if ($msg instanceof Ping) {
                    $received[] = $msg->id;
                }

                return Behavior::same();
            },
        )), 'target');

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([Ping::class => $target]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertSame(['a', 'b'], $received);
        self::assertCount(2, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }

    #[Test]
    public function rejectsUnroutableMessagesByDefault(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('receiver-unroutable', $runtime);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new Ping('lost')));

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertCount(1, $transport->getRejected());
        self::assertCount(0, $transport->getAcknowledged());
    }

    #[Test]
    public function forwardsUnroutableMessagesToDeadLettersWhenConfigured(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('receiver-deadletters', $runtime);
        $transport = new InMemoryTransport();
        $message = new Ping('dead');
        $transport->send(new Envelope($message));

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([]),
            ReceiverActorConfig::default()
                ->withPollInterval(Duration::millis(20))
                ->withUnroutablePolicy(UnroutablePolicy::DeadLetters),
            $system->deadLetters(),
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(200), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertContains($message, $system->deadLetters()->captured());
        self::assertCount(1, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }

    #[Test]
    public function backpressuredEnqueueIsNotAckedAndIsRedeliveredLater(): void
    {
        $runtime = new FiberRuntime();
        $system = ActorSystem::create('receiver-backpressure', $runtime);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new Ping('bp')));
        $fake = new TogglableBackpressureRef();

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([Ping::class => $fake]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(150), static function () use ($fake): void {
            $fake->result = EnqueueResult::Accepted;
        });
        $runtime->scheduleOnce(Duration::millis(400), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        self::assertCount(1, $fake->accepted);
        self::assertInstanceOf(Ping::class, $fake->accepted[0]);
        self::assertCount(1, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }

    #[Test]
    public function droppedEnqueueIncrementsDroppedCounterAndLeavesMessageUnacked(): void
    {
        $runtime = new FiberRuntime();
        $observability = new RecordingObservability();
        $system = ActorSystem::create('receiver-dropped', $runtime, observability: $observability);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new Ping('dropped')));
        $fake = new TogglableBackpressureRef();
        $fake->result = EnqueueResult::Dropped;

        $system->spawn(Props::fromBehavior(ReceiverActor::create(
            $transport,
            new MapMessageRouter([Ping::class => $fake]),
            ReceiverActorConfig::default()->withPollInterval(Duration::millis(20)),
            null,
            null,
            null,
            $observability,
        )), 'receiver');

        $runtime->scheduleOnce(Duration::millis(300), static function () use ($system): void {
            $system->shutdown(Duration::seconds(1));
        });
        $system->run();

        // The un-acked message is redelivered on every poll tick, so the
        // dropped counter records one increment per delivery attempt.
        self::assertGreaterThanOrEqual(1, $observability->meter->sum('nexus.messenger.enqueue.dropped'));
        self::assertCount(0, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }
}
