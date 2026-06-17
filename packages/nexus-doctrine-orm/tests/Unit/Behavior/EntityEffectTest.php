<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Tests\Unit\Behavior;

use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffect;
use Monadial\Nexus\Doctrine\Orm\Behavior\EntityEffectKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
