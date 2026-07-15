<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Tests\Unit;

use Monadial\Nexus\App\AsActor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AsActor::class)]
final class AsActorTest extends TestCase
{
    #[Test]
    public function exposesTheActorName(): void
    {
        self::assertSame('greeter', new AsActor('greeter')->name);
    }

    #[Test]
    public function isReadableViaReflectionAsAClassAttribute(): void
    {
        $attrs = (new ReflectionClass(FixtureAttributedActor::class))->getAttributes(AsActor::class);
        self::assertCount(1, $attrs);
        self::assertSame('fixture', $attrs[0]->newInstance()->name);
    }
}

#[AsActor('fixture')]
final class FixtureAttributedActor {}
