<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Inbox;

use Monadial\Nexus\Ddd\Messaging\Inbox\InMemoryMessageInbox;
use Monadial\Nexus\Ddd\Messaging\Inbox\MessageInbox;
use Monadial\Nexus\Ddd\Messaging\Tests\Support\MessageInboxContractTest;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(InMemoryMessageInbox::class)]
final class InMemoryMessageInboxContractTest extends MessageInboxContractTest
{
    #[Override]
    protected function createInbox(): MessageInbox
    {
        return new InMemoryMessageInbox();
    }
}
