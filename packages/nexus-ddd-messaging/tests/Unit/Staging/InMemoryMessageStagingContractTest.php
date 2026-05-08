<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Staging;

use Monadial\Nexus\Ddd\Messaging\Context\MessageContextStack;
use Monadial\Nexus\Ddd\Messaging\Staging\InMemoryMessageStaging;
use Monadial\Nexus\Ddd\Messaging\Staging\MessageStaging;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\MessageStagingContractTest;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedCommandBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\RecordingEnvelopedEventBus;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\SystemClock;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(InMemoryMessageStaging::class)]
final class InMemoryMessageStagingContractTest extends MessageStagingContractTest
{
    #[Override]
    protected function createStaging(
        RecordingEnvelopedCommandBus $cmdBus,
        RecordingEnvelopedEventBus $evtBus,
    ): MessageStaging {
        return new InMemoryMessageStaging(
            $cmdBus,
            $evtBus,
            MessageContextStack::default(),
            new SystemClock(),
            new NullLogger(),
        );
    }
}
