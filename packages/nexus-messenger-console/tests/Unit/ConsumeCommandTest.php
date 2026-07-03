<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console\Tests\Unit;

use ArrayObject;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\DeadLetterRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Messenger\Console\CallbackConsumerSetup;
use Monadial\Nexus\Messenger\Console\ConsumeCommand;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[CoversClass(CallbackConsumerSetup::class)]
#[CoversClass(ConsumeCommand::class)]
final class ConsumeCommandTest extends TestCase
{
    #[Test]
    public function consumesPreSeededMessagesAndReturnsSuccess(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));

        // DeadLetterRef does not implement BackpressureCapable; ReceiverActor
        // falls into the plain tell() branch which acks the envelope and
        // increments the processed count — so the watchdog receives
        // MessagesProcessed and fires graceful shutdown after 3 messages.
        $router = new MapMessageRouter([stdClass::class => new DeadLetterRef()]);

        $command = new ConsumeCommand(
            new FiberRuntime(),
            $transport,
            $router,
        );

        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--limit' => '3',
            '--poll-interval' => '20',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(3, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }

    #[Test]
    public function memoryLimitOptionIsAccepted(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));

        $router = new MapMessageRouter([stdClass::class => new DeadLetterRef()]);

        $command = new ConsumeCommand(
            new FiberRuntime(),
            $transport,
            $router,
        );

        $tester = new CommandTester($command);

        // --memory-limit alone: the watchdog runs but the limit is enormous.
        // Add --limit=2 so the run terminates promptly.
        $exitCode = $tester->execute([
            '--limit' => '2',
            '--memory-limit' => '4G',
            '--poll-interval' => '20',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $transport->getAcknowledged());
    }

    #[Test]
    public function startupSummaryMentionsReceiverCount(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new stdClass()));

        $router = new MapMessageRouter([stdClass::class => new DeadLetterRef()]);

        $command = new ConsumeCommand(
            new FiberRuntime(),
            $transport,
            $router,
        );

        $tester = new CommandTester($command);
        $tester->execute([
            '--limit' => '1',
            '--poll-interval' => '20',
            '--receivers' => '1',
        ]);

        self::assertStringContainsString('1 receiver', $tester->getDisplay());
    }

    #[Test]
    public function callbackConsumerSetupSpawnsActorOnBootedSystem(): void
    {
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new stdClass()));
        $transport->send(new Envelope(new stdClass()));

        /** @var ArrayObject<int, object> $received */
        $received = new ArrayObject();

        $command = new ConsumeCommand(
            new FiberRuntime(),
            $transport,
            new CallbackConsumerSetup(static function (ActorSystem $system) use ($received): MessageRouter {
                $ref = $system->spawn(
                    Props::fromBehavior(Behavior::receive(
                        static function (ActorContext $ctx, object $msg) use ($received): Behavior {
                            $received->append($msg);

                            return Behavior::same();
                        },
                    )),
                    'handler',
                );

                return new MapMessageRouter([stdClass::class => $ref]);
            }),
        );

        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--limit' => '2',
            '--poll-interval' => '20',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $received);
        self::assertCount(2, $transport->getAcknowledged());
    }

    #[Test]
    public function deadLettersForwardUnroutableMessagesAndAcksWithoutCountingTowardLimit(): void
    {
        $unroutableMsg = new class {};
        $routableMsg = new class {};

        $transport = new InMemoryTransport();
        $transport->send(new Envelope($unroutableMsg));
        $transport->send(new Envelope($routableMsg));

        $routableMsgClass = $routableMsg::class;

        $command = new ConsumeCommand(
            new FiberRuntime(),
            $transport,
            new CallbackConsumerSetup(static function (ActorSystem $system) use ($routableMsgClass): MessageRouter {
                $ref = $system->spawn(
                    Props::fromBehavior(Behavior::receive(
                        static function (ActorContext $ctx, object $msg): Behavior {
                            return Behavior::same();
                        },
                    )),
                    'handler',
                );

                return new MapMessageRouter([$routableMsgClass => $ref]);
            }),
        );

        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--dead-letters' => true,
            '--limit' => '1',
            '--poll-interval' => '20',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $transport->getAcknowledged());
        self::assertCount(0, $transport->getRejected());
    }
}
