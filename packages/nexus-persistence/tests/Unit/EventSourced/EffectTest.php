<?php

declare(strict_types=1);

namespace Monadial\Nexus\Persistence\Tests\Unit\EventSourced;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Persistence\EventSourced\Effect;
use Monadial\Nexus\Persistence\EventSourced\EffectType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Effect::class)]
#[CoversClass(EffectType::class)]
final class EffectTest extends TestCase
{
    #[Test]
    public function persistStoresEvents(): void
    {
        $event1 = new stdClass();
        $event1->type = 'OrderPlaced';
        $event2 = new stdClass();
        $event2->type = 'OrderConfirmed';

        $effect = Effect::persist($event1, $event2);

        self::assertSame(EffectType::Persist, $effect->type);
        self::assertCount(2, $effect->events);
        self::assertSame($event1, $effect->events[0]);
        self::assertSame($event2, $effect->events[1]);
    }

    #[Test]
    public function persistWithSingleEvent(): void
    {
        $event = new stdClass();
        $event->type = 'OrderPlaced';

        $effect = Effect::persist($event);

        self::assertSame(EffectType::Persist, $effect->type);
        self::assertCount(1, $effect->events);
        self::assertSame($event, $effect->events[0]);
    }

    #[Test]
    public function noneHasNoEvents(): void
    {
        $effect = Effect::none();

        self::assertSame(EffectType::None, $effect->type);
        self::assertCount(0, $effect->events);
        self::assertNull($effect->replyTo);
        self::assertNull($effect->replyMsg);
        self::assertCount(0, $effect->sideEffects);
    }

    #[Test]
    public function unhandledFlag(): void
    {
        $effect = Effect::unhandled();

        self::assertSame(EffectType::Unhandled, $effect->type);
        self::assertCount(0, $effect->events);
    }

    #[Test]
    public function stashFlag(): void
    {
        $effect = Effect::stash();

        self::assertSame(EffectType::Stash, $effect->type);
        self::assertCount(0, $effect->events);
    }

    #[Test]
    public function stopFlag(): void
    {
        $effect = Effect::stop();

        self::assertSame(EffectType::Stop, $effect->type);
        self::assertCount(0, $effect->events);
    }

    #[Test]
    public function replyStoresRefAndMessage(): void
    {
        $ref = $this->createMock(ActorRef::class);
        $msg = new stdClass();
        $msg->result = 'ok';

        $effect = Effect::reply($ref, $msg);

        self::assertSame(EffectType::Reply, $effect->type);
        self::assertSame($ref, $effect->replyTo);
        self::assertSame($msg, $effect->replyMsg);
    }

    #[Test]
    public function thenReplyChainingStoresSideEffect(): void
    {
        $ref = $this->createMock(ActorRef::class);
        $fn = static fn (object $state): object => $state;

        $effect = Effect::persist(new stdClass())
            ->thenReply($ref, $fn);

        self::assertSame(EffectType::Persist, $effect->type);
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

        $fn = static fn (object $state): object => $replyMsg;

        $effect = Effect::persist(new stdClass())
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

        $effect = Effect::persist(new stdClass())
            ->thenRun($fn);

        self::assertSame(EffectType::Persist, $effect->type);
        self::assertCount(1, $effect->sideEffects);
        self::assertInstanceOf(Closure::class, $effect->sideEffects[0]);

        // Execute the stored side effect
        $effect->sideEffects[0](new stdClass());
        self::assertTrue($called);
    }

    #[Test]
    public function multipleChainingPreservesAllSideEffects(): void
    {
        $ref1 = $this->createMock(ActorRef::class);
        $ref2 = $this->createMock(ActorRef::class);

        $effect = Effect::persist(new stdClass())
            ->thenReply($ref1, static fn (object $s): object => new stdClass())
            ->thenRun(static function (object $s): void {
            })
            ->thenReply($ref2, static fn (object $s): object => new stdClass());

        self::assertCount(3, $effect->sideEffects);
    }

    #[Test]
    public function chainingReturnsNewInstance(): void
    {
        $ref = $this->createMock(ActorRef::class);
        $original = Effect::persist(new stdClass());
        $chained = $original->thenRun(static function (object $s): void {
        });

        self::assertNotSame($original, $chained);
        self::assertCount(0, $original->sideEffects);
        self::assertCount(1, $chained->sideEffects);
    }

    #[Test]
    public function persistWithMultipleEvents(): void
    {
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $e = new stdClass();
            $e->index = $i;
            $events[] = $e;
        }

        $effect = Effect::persist(...$events);

        self::assertSame(EffectType::Persist, $effect->type);
        self::assertCount(5, $effect->events);
        for ($i = 0; $i < 5; $i++) {
            self::assertSame($i, $effect->events[$i]->index);
        }
    }
}
