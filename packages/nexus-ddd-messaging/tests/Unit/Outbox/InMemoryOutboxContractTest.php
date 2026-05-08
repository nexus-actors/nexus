<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Outbox;

use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Outbox\InMemoryOutbox;
use Monadial\Nexus\Ddd\Messaging\Outbox\Outbox;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\OutboxContractTest;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(InMemoryOutbox::class)]
final class InMemoryOutboxContractTest extends OutboxContractTest
{
    #[Override]
    protected function createOutbox(RecordingEnvelopedCommandBus $cmdBus, RecordingEnvelopedEventBus $evtBus): Outbox
    {
        return new InMemoryOutbox(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
    }
}
