<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorTag;
use Monadial\Nexus\Core\Actor\DefaultStashBuffer;
use Monadial\Nexus\Core\Exception\StashOverflowException;
use Monadial\Nexus\Core\Mailbox\Envelope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultStashBuffer::class)]
final class StashBufferTest extends TestCase
{
    #[Test]
    public function starts_empty(): void
    {
        $stash = new DefaultStashBuffer(10);

        self::assertTrue($stash->isEmpty());
        self::assertFalse($stash->isFull());
        self::assertSame(0, $stash->size());
        self::assertSame(10, $stash->capacity());
    }

    #[Test]
    public function stash_adds_envelope(): void
    {
        $stash = new DefaultStashBuffer(10);
        $envelope = $this->makeEnvelope('hello');

        $stash->stash($envelope);

        self::assertFalse($stash->isEmpty());
        self::assertSame(1, $stash->size());
    }

    #[Test]
    public function stash_throws_when_full(): void
    {
        $stash = new DefaultStashBuffer(2);

        $stash->stash($this->makeEnvelope('one'));
        $stash->stash($this->makeEnvelope('two'));

        self::assertTrue($stash->isFull());

        $this->expectException(StashOverflowException::class);
        $stash->stash($this->makeEnvelope('three'));
    }

    #[Test]
    public function unstash_all_returns_unstash_behavior(): void
    {
        $stash = new DefaultStashBuffer(10);
        $stash->stash($this->makeEnvelope('first'));
        $stash->stash($this->makeEnvelope('second'));

        $target = Behavior::receive(static fn() => Behavior::same());
        $result = $stash->unstashAll($target);

        // Returns a special behavior for ActorCell to handle
        self::assertSame(BehaviorTag::UnstashAll, $result->tag());

        // Stash is cleared after unstashAll
        self::assertTrue($stash->isEmpty());
        self::assertSame(0, $stash->size());
    }

    #[Test]
    public function unstash_all_with_empty_buffer_returns_target_directly(): void
    {
        $stash = new DefaultStashBuffer(10);

        $target = Behavior::receive(static fn() => Behavior::same());
        $result = $stash->unstashAll($target);

        // When empty, just return the target behavior directly (no replay needed)
        self::assertSame($target, $result);
    }

    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress PossiblyNullFunctionCall
     * @psalm-suppress MixedArrayAccess
     */
    #[Test]
    public function unstash_all_preserves_envelope_order(): void
    {
        $stash = new DefaultStashBuffer(10);
        $env1 = $this->makeEnvelope('first');
        $env2 = $this->makeEnvelope('second');
        $env3 = $this->makeEnvelope('third');

        $stash->stash($env1);
        $stash->stash($env2);
        $stash->stash($env3);

        $target = Behavior::receive(static fn() => Behavior::same());
        $result = $stash->unstashAll($target);

        // The handler should return the envelopes and target
        $data = $result->handler()->get();
        $payload = $data();
        self::assertSame([$env1, $env2, $env3], $payload['envelopes']);
        self::assertSame($target, $payload['target']);
    }

    #[Test]
    public function is_full_at_capacity(): void
    {
        $stash = new DefaultStashBuffer(1);

        self::assertFalse($stash->isFull());

        $stash->stash($this->makeEnvelope('one'));

        self::assertTrue($stash->isFull());
    }

    private function makeEnvelope(string $value): Envelope
    {
        return Envelope::of(
            new StashMessage($value),
            ActorPath::root(),
            ActorPath::fromString('/user/test'),
        );
    }
}
