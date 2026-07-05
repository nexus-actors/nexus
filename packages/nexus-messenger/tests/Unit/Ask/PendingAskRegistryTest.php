<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Tests\Unit\Ask;

use Monadial\Nexus\Messenger\Ask\PendingAskRegistry;
use Monadial\Nexus\Messenger\Exception\AskCapacityExceededException;
use Monadial\Nexus\Messenger\Tests\Support\RecordingFutureSlot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingAskRegistry::class)]
#[CoversClass(AskCapacityExceededException::class)]
final class PendingAskRegistryTest extends TestCase
{
    #[Test]
    public function register_and_resolve_happy_path(): void
    {
        $registry = new PendingAskRegistry(maxPending: 10);
        $slot = new RecordingFutureSlot();
        $reply = new class {
            public string $data = 'reply';
        };

        $registry->register('ask-1', $slot);
        $result = $registry->resolve('ask-1', $reply);

        self::assertTrue($result);
        self::assertSame($reply, $slot->getResolvedValue());
        self::assertTrue($slot->isResolved());
    }

    #[Test]
    public function duplicate_resolve_returns_false_and_does_not_re_resolve(): void
    {
        $registry = new PendingAskRegistry(maxPending: 10);
        $slot = new RecordingFutureSlot();
        $reply1 = new class {
            public string $data = 'reply1';
        };
        $reply2 = new class {
            public string $data = 'reply2';
        };

        $registry->register('ask-1', $slot);
        $result1 = $registry->resolve('ask-1', $reply1);
        $result2 = $registry->resolve('ask-1', $reply2);

        self::assertTrue($result1);
        self::assertFalse($result2);
        self::assertSame($reply1, $slot->getResolvedValue());
    }

    #[Test]
    public function unknown_id_resolve_returns_false(): void
    {
        $registry = new PendingAskRegistry(maxPending: 10);
        $reply = new class {
            public string $data = 'reply';
        };

        $result = $registry->resolve('unknown-id', $reply);

        self::assertFalse($result);
    }

    #[Test]
    public function remove_clears_and_subsequent_resolve_returns_false(): void
    {
        $registry = new PendingAskRegistry(maxPending: 10);
        $slot = new RecordingFutureSlot();
        $reply = new class {
            public string $data = 'reply';
        };

        $registry->register('ask-1', $slot);
        $removed = $registry->remove('ask-1');
        $result = $registry->resolve('ask-1', $reply);

        self::assertSame($slot, $removed);
        self::assertFalse($result);
        self::assertNull($slot->getResolvedValue());
    }

    #[Test]
    public function remove_unknown_id_returns_null(): void
    {
        $registry = new PendingAskRegistry(maxPending: 10);

        $removed = $registry->remove('unknown-id');

        self::assertNull($removed);
    }

    #[Test]
    public function capacity_boundary_at_max_pending_throws_exception(): void
    {
        $registry = new PendingAskRegistry(maxPending: 2);
        $slot1 = new RecordingFutureSlot();
        $slot2 = new RecordingFutureSlot();
        $slot3 = new RecordingFutureSlot();

        $registry->register('ask-1', $slot1);
        $registry->register('ask-2', $slot2);

        self::expectException(AskCapacityExceededException::class);
        self::expectExceptionMessageMatches('/max.*2.*current.*2/i');

        $registry->register('ask-3', $slot3);
    }

    #[Test]
    public function capacity_exception_message_includes_cap_and_current_count(): void
    {
        $registry = new PendingAskRegistry(maxPending: 1);
        $slot1 = new RecordingFutureSlot();
        $slot2 = new RecordingFutureSlot();

        $registry->register('ask-1', $slot1);

        try {
            $registry->register('ask-2', $slot2);
            self::fail('Expected AskCapacityExceededException');
        } catch (AskCapacityExceededException $e) {
            self::assertStringContainsString('1', $e->getMessage());
            self::assertStringContainsString('1', $e->getMessage());
        }
    }

    #[Test]
    public function count_returns_number_of_pending_asks(): void
    {
        $registry = new PendingAskRegistry(maxPending: 10);
        $slot1 = new RecordingFutureSlot();
        $slot2 = new RecordingFutureSlot();
        $slot3 = new RecordingFutureSlot();
        $reply = new class {
            public string $data = 'reply';
        };

        self::assertSame(0, $registry->count());

        $registry->register('ask-1', $slot1);
        self::assertSame(1, $registry->count());

        $registry->register('ask-2', $slot2);
        self::assertSame(2, $registry->count());

        $registry->register('ask-3', $slot3);
        self::assertSame(3, $registry->count());

        $registry->resolve('ask-1', $reply);
        self::assertSame(2, $registry->count());

        $registry->remove('ask-2');
        self::assertSame(1, $registry->count());
    }
}
