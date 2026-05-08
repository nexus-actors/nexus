<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Outbox;

use Fp\Functional\Option\Option;
use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Message\Command;
use Monadial\Nexus\Ddd\Messaging\Outbox\InMemoryOutbox;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

#[CoversClass(InMemoryOutbox::class)]
final class InMemoryOutboxLoggerTest extends TestCase
{
    #[Test]
    public function flushEmitsOneWarningPerCallMentioningAtMostOnce(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            #[Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                if ($level === 'warning') {
                    $this->warnings[] = (string) $message;
                }
            }
        };

        $cmdBus = new RecordingEnvelopedCommandBus();
        $evtBus = new RecordingEnvelopedEventBus();
        $outbox = new InMemoryOutbox(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            $logger,
        );

        $outbox->appendCommand(new class implements Command {}, Option::none());
        $outbox->flush();
        $outbox->flush();

        self::assertCount(2, $logger->warnings);
        self::assertStringContainsString('at-most-once', $logger->warnings[0]);
        self::assertStringContainsString('at-most-once', $logger->warnings[1]);
    }
}
