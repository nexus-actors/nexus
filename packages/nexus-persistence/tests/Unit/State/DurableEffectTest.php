<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\State;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Persistence\State\DurableEffect;
use Monadial\Nexus\Persistence\State\DurableEffectType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(DurableEffect::class)]
#[CoversClass(DurableEffectType::class)]
final class DurableEffectTest extends TestCase
{
    #[Test]
    public function persistStoresNewState(): void
    {
        $state = new stdClass();
        $state->count = 42;

        $effect = DurableEffect::persist($state);

        self::assertSame(DurableEffectType::Persist, $effect->type);
        self::assertSame($state, $effect->state);
    }

    #[Test]
    public function noneHasNoState(): void
    {
        $effect = DurableEffect::none();

        self::assertSame(DurableEffectType::None, $effect->type);
        self::assertNull($effect->state);
        self::assertNull($effect->replyTo);
        self::assertNull($effect->replyMsg);
        self::assertCount(0, $effect->sideEffects);
    }

    #[Test]
    public function unhandledFlag(): void
    {
        $effect = DurableEffect::unhandled();

        self::assertSame(DurableEffectType::Unhandled, $effect->type);
        self::assertNull($effect->state);
    }

    #[Test]
    public function stashFlag(): void
    {
        $effect = DurableEffect::stash();

        self::assertSame(DurableEffectType::Stash, $effect->type);
        self::assertNull($effect->state);
    }

    #[Test]
    public function stopFlag(): void
    {
        $effect = DurableEffect::stop();

        self::assertSame(DurableEffectType::Stop, $effect->type);
        self::assertNull($effect->state);
    }

    #[Test]
    public function replyStoresRefAndMessage(): void
    {
        $ref = $this->createStub(ActorRef::class);
        $msg = new stdClass();
        $msg->result = 'ok';

        $effect = DurableEffect::reply($ref, $msg);

        self::assertSame(DurableEffectType::Reply, $effect->type);
        self::assertSame($ref, $effect->replyTo);
        self::assertSame($msg, $effect->replyMsg);
    }

    #[Test]
    public function thenReplyChainingStoresSideEffect(): void
    {
        $ref = $this->createStub(ActorRef::class);
        $fn = static fn(object $state): object => $state;

        $state = new stdClass();
        $state->count = 1;

        $effect = DurableEffect::persist($state)
            ->thenReply($ref, $fn);

        self::assertSame(DurableEffectType::Persist, $effect->type);
        self::assertCount(1, $effect->sideEffects);
        self::assertInstanceOf(Closure::class, $effect->sideEffects[0]);
    }

    #[Test]
    public function thenReplyExecutesTellOnRef(): void
    {
        $state = new stdClass();
        $state->value = 42;

        $replyMsg = new stdClass();
        $replyMsg->answer = 42;

        $ref = $this->createMock(ActorRef::class);
        $ref->expects(self::once())
            ->method('tell')
            ->with($replyMsg);

        $fn = static fn(object $state): object => $replyMsg;

        $effect = DurableEffect::persist($state)
            ->thenReply($ref, $fn);

        // Execute the stored side effect
        $effect->sideEffects[0]($state);
    }

    #[Test]
    public function thenRunChainingStoresSideEffect(): void
    {
        $called = false;
        $fn = static function (object $state) use (&$called): void {
            $called = true;
        };

        $state = new stdClass();
        $state->count = 1;

        $effect = DurableEffect::persist($state)
            ->thenRun($fn);

        self::assertSame(DurableEffectType::Persist, $effect->type);
        self::assertCount(1, $effect->sideEffects);
        self::assertInstanceOf(Closure::class, $effect->sideEffects[0]);

        // Execute the stored side effect
        $effect->sideEffects[0](new stdClass());
        self::assertTrue($called);
    }

    #[Test]
    public function multipleChainingPreservesAllSideEffects(): void
    {
        $ref1 = $this->createStub(ActorRef::class);
        $ref2 = $this->createStub(ActorRef::class);

        $state = new stdClass();

        $effect = DurableEffect::persist($state)
            ->thenReply($ref1, static fn(object $s): object => new stdClass())
            ->thenRun(static function (object $s): void {})
            ->thenReply($ref2, static fn(object $s): object => new stdClass());

        self::assertCount(3, $effect->sideEffects);
    }

    #[Test]
    public function chainingReturnsNewInstance(): void
    {
        $state = new stdClass();
        $original = DurableEffect::persist($state);
        $chained = $original->thenRun(static function (object $s): void {});

        self::assertNotSame($original, $chained);
        self::assertCount(0, $original->sideEffects);
        self::assertCount(1, $chained->sideEffects);
    }
}
