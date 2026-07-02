<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffectKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

#[CoversClass(EntityEffect::class)]
#[CoversClass(EntityEffectKind::class)]
final class EntityEffectTest extends TestCase
{
    #[Test]
    public function sameHasNoOpKind(): void
    {
        self::assertSame(EntityEffectKind::Same, EntityEffect::same()->kind);
    }

    #[Test]
    public function persistHasFlushKind(): void
    {
        self::assertSame(EntityEffectKind::Persist, EntityEffect::persist()->kind);
    }

    #[Test]
    public function removeHasRemoveKind(): void
    {
        self::assertSame(EntityEffectKind::Remove, EntityEffect::remove()->kind);
    }

    #[Test]
    public function stopHasStopKind(): void
    {
        self::assertSame(EntityEffectKind::Stop, EntityEffect::stop()->kind);
    }

    #[Test]
    public function stashHasStashKind(): void
    {
        self::assertSame(EntityEffectKind::Stash, EntityEffect::stash()->kind);
    }

    #[Test]
    public function terminalEffectsHaveEmptyHooks(): void
    {
        self::assertEmpty(EntityEffect::same()->runHooks);
        self::assertEmpty(EntityEffect::persist()->replyHooks);
        self::assertNull(EntityEffect::same()->immediateReplyRef);
        self::assertNull(EntityEffect::same()->immediateReplyMessage);
    }

    #[Test]
    public function replyCarriesImmediateRefAndMessage(): void
    {
        $ref = $this->createStub(ActorRef::class);
        $msg = new stdClass();

        $effect = EntityEffect::reply($ref, $msg);
        self::assertSame($ref, $effect->immediateReplyRef);
        self::assertSame($msg, $effect->immediateReplyMessage);
        self::assertSame(EntityEffectKind::Same, $effect->kind);
    }

    #[Test]
    public function thenRunAppendsHook(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $effect = EntityEffect::persist()->thenRun(static fn(object $e) => null);
        self::assertCount(1, $effect->runHooks);
        self::assertSame(EntityEffectKind::Persist, $effect->kind);
    }

    #[Test]
    public function thenReplyAppendsHook(): void
    {
        $ref = $this->createStub(ActorRef::class);
        /** @psalm-suppress UnusedClosureParam */
        $effect = EntityEffect::persist()->thenReply($ref, static fn(object $e): object => new stdClass());
        self::assertCount(1, $effect->replyHooks);
        self::assertSame($ref, $effect->replyHooks[0]['ref']);
    }

    #[Test]
    public function thenReplyOnFailureAppendsHookAndReturnsNewInstance(): void
    {
        $ref = $this->createStub(ActorRef::class);
        $base = EntityEffect::persist();
        /** @psalm-suppress UnusedClosureParam */
        $composed = $base->thenReplyOnFailure($ref, static fn(Throwable $e): object => new stdClass());

        self::assertNotSame($base, $composed);
        self::assertCount(0, $base->failureHooks);
        self::assertCount(1, $composed->failureHooks);
        self::assertSame($ref, $composed->failureHooks[0]['ref']);
        self::assertSame(EntityEffectKind::Persist, $composed->kind);
    }

    #[Test]
    public function failureHooksCoexistWithSuccessReplyHooks(): void
    {
        $successRef = $this->createStub(ActorRef::class);
        $failureRef = $this->createStub(ActorRef::class);
        /** @psalm-suppress UnusedClosureParam */
        $effect = EntityEffect::persist()
            ->thenReply($successRef, static fn(object $e): object => new stdClass())
            ->thenReplyOnFailure($failureRef, static fn(Throwable $e): object => new stdClass());

        self::assertCount(1, $effect->replyHooks);
        self::assertCount(1, $effect->failureHooks);
        self::assertSame($successRef, $effect->replyHooks[0]['ref']);
        self::assertSame($failureRef, $effect->failureHooks[0]['ref']);
    }

    #[Test]
    public function terminalEffectsHaveEmptyFailureHooks(): void
    {
        self::assertEmpty(EntityEffect::persist()->failureHooks);
        self::assertEmpty(EntityEffect::same()->failureHooks);
    }

    #[Test]
    public function composersChain(): void
    {
        $ref = $this->createStub(ActorRef::class);
        /** @psalm-suppress UnusedClosureParam */
        $effect = EntityEffect::persist()
            ->thenRun(static fn(object $e) => null)
            ->thenReply($ref, static fn(object $e): object => new stdClass());

        self::assertCount(1, $effect->runHooks);
        self::assertCount(1, $effect->replyHooks);
    }

    #[Test]
    public function thenRunReturnsNewInstance(): void
    {
        $base = EntityEffect::persist();
        /** @psalm-suppress UnusedClosureParam */
        $composed = $base->thenRun(static fn(object $e) => null);

        self::assertNotSame($base, $composed);
        self::assertCount(0, $base->runHooks);
        self::assertCount(1, $composed->runHooks);
    }

    #[Test]
    public function removeWithThenRunCarriesBothKindAndHook(): void
    {
        /** @psalm-suppress UnusedClosureParam */
        $effect = EntityEffect::remove()->thenRun(static fn(object $e) => null);

        self::assertSame(EntityEffectKind::Remove, $effect->kind);
        self::assertCount(1, $effect->runHooks);
    }

    #[Test]
    public function stopRetainsHooksOnEffect(): void
    {
        // Hooks ARE stored on the effect; the runner (Plan 3 T7) is responsible
        // for NOT firing them when kind === Stop (stop means "no flush" → entity
        // may be inconsistent). This test verifies the data shape only.
        /** @psalm-suppress UnusedClosureParam */
        $effect = EntityEffect::stop()->thenRun(static fn(object $e) => null);

        self::assertSame(EntityEffectKind::Stop, $effect->kind);
        self::assertCount(1, $effect->runHooks);
    }

    #[Test]
    public function persistWithReplyAndThenRunCombines(): void
    {
        $ref = $this->createStub(ActorRef::class);
        /** @psalm-suppress UnusedClosureParam */
        $effect = EntityEffect::reply($ref, new stdClass())
            ->thenRun(static fn(object $e) => null);

        // reply() set kind to Same with immediateReplyRef populated
        self::assertSame(EntityEffectKind::Same, $effect->kind);
        self::assertSame($ref, $effect->immediateReplyRef);
        self::assertCount(1, $effect->runHooks);
    }
}
