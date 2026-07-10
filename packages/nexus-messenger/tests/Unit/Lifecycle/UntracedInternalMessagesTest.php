<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Lifecycle;

use Monadial\Nexus\Core\Actor\UntracedMessage;
use Monadial\Nexus\Messenger\Consumer\Poll;
use Monadial\Nexus\Messenger\Lifecycle\MessagesProcessed;
use Monadial\Nexus\Messenger\Lifecycle\Tick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Poll::class)]
#[CoversClass(Tick::class)]
#[CoversClass(MessagesProcessed::class)]
final class UntracedInternalMessagesTest extends TestCase
{
    #[Test]
    public function pollIsUntraced(): void
    {
        self::assertInstanceOf(UntracedMessage::class, new Poll());
    }

    #[Test]
    public function tickIsUntraced(): void
    {
        self::assertInstanceOf(UntracedMessage::class, new Tick());
    }

    #[Test]
    public function messagesProcessedIsUntraced(): void
    {
        self::assertInstanceOf(UntracedMessage::class, new MessagesProcessed(5));
    }
}
