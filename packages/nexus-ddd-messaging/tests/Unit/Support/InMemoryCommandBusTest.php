<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Support;

use DateTimeImmutable;
use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Envelope\Envelope;
use Monadial\Nexus\Ddd\Messaging\Handler\CommandHandler;
use Monadial\Nexus\Ddd\Messaging\Identity\MessageId;
use Monadial\Nexus\Ddd\Messaging\Inbox\MessageInbox;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Metadata\MessageMetadata;
use Monadial\Nexus\Ddd\Messaging\Resolution\CommandHandlerLocator;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\InMemoryCommandBus;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Throwable;

#[CoversClass(InMemoryCommandBus::class)]
final class InMemoryCommandBusTest extends TestCase
{
    #[Test]
    public function successfulHandlerCallsMarkProcessed(): void
    {
        $inbox = new RecordingInbox();
        $handler = new class implements CommandHandler {
            public function __invoke(Command $cmd): void
            {
                // no-op success path
            }
        };
        $bus = $this->busFor($handler, $inbox);
        $envelope = $this->buildEnvelope();

        $bus->dispatchEnveloped($envelope);

        self::assertSame(['tryReserve', 'markCompleted'], $inbox->calls);
    }

    #[Test]
    public function failingHandlerCallsReleaseAndRethrows(): void
    {
        $inbox = new RecordingInbox();
        $handler = new class implements CommandHandler {
            public function __invoke(Command $cmd): void
            {
                throw new RuntimeException('boom');
            }
        };
        $bus = $this->busFor($handler, $inbox);
        $envelope = $this->buildEnvelope();

        try {
            $bus->dispatchEnveloped($envelope);
            self::fail('expected RuntimeException');
        } catch (Throwable $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(['tryReserve', 'release'], $inbox->calls);
    }

    #[Test]
    public function alreadyReservedIdIsSilentlySkippedAndDoesNotInvokeHandler(): void
    {
        $inbox = new RecordingInbox(reserveSucceeds: false);
        $invoked = false;
        $handler = new class ($invoked) implements CommandHandler {
            public function __construct(private bool &$invoked) {}

            public function __invoke(Command $cmd): void
            {
                $this->invoked = true;
            }
        };
        $bus = $this->busFor($handler, $inbox);

        $bus->dispatchEnveloped($this->buildEnvelope());

        self::assertFalse($invoked);
        self::assertSame(['tryReserve'], $inbox->calls);
    }

    private function busFor(CommandHandler $handler, MessageInbox $inbox): InMemoryCommandBus
    {
        $locator = new class ($handler) implements CommandHandlerLocator {
            public function __construct(private CommandHandler $h) {}

            #[Override]
            public function locate(Command $command): CommandHandler
            {
                return $this->h;
            }
        };
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-05-08T12:00:00+00:00');
            }
        };

        return new InMemoryCommandBus($locator, $inbox, MessageContextStack::default(), $clock);
    }

    /** @return Envelope<Command> */
    private function buildEnvelope(): Envelope
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-05-08T12:00:00+00:00');
            }
        };

        return new Envelope(
            new class implements Command {},
            MessageMetadata::root($clock),
        );
    }
}

final class RecordingInbox implements MessageInbox
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private bool $reserveSucceeds = true) {}

    /**
     * @param class-string $handlerClass
     */
    #[Override]
    public function tryReserve(string $handlerClass, MessageId $messageId): bool
    {
        $this->calls[] = 'tryReserve';

        return $this->reserveSucceeds;
    }

    /**
     * @param class-string $handlerClass
     * @param Option<DateTimeImmutable> $at
     */
    #[Override]
    public function markCompleted(string $handlerClass, MessageId $messageId, Option $at): void
    {
        $this->calls[] = 'markCompleted';
    }

    /**
     * @param class-string $handlerClass
     */
    #[Override]
    public function release(string $handlerClass, MessageId $messageId): void
    {
        $this->calls[] = 'release';
    }
}
