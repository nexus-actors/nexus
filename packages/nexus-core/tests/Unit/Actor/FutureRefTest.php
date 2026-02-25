<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\FutureRef;
use Monadial\Nexus\Core\Async\FutureSlot;
use Monadial\Nexus\Core\Duration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

#[CoversClass(FutureRef::class)]
final class FutureRefTest extends TestCase
{
    #[Test]
    public function tell_resolves_the_slot(): void
    {
        $slot = $this->createMock(FutureSlot::class);
        $slot->expects(self::once())
            ->method('resolve')
            ->with(self::isInstanceOf(stdClass::class));

        $ref = new FutureRef(ActorPath::fromString('/temp/ask-1'), $slot);
        $ref->tell(new stdClass());
    }

    #[Test]
    public function path_returns_configured_path(): void
    {
        $path = ActorPath::fromString('/temp/ask-42');
        $slot = $this->createStub(FutureSlot::class);
        $ref = new FutureRef($path, $slot);

        self::assertTrue($path->equals($ref->path()));
    }

    #[Test]
    public function ask_throws(): void
    {
        $slot = $this->createStub(FutureSlot::class);
        $ref = new FutureRef(ActorPath::fromString('/temp/ask-1'), $slot);

        $this->expectException(RuntimeException::class);
        (void) $ref->ask(new stdClass(), Duration::seconds(1));
    }

    #[Test]
    public function is_alive_returns_true_when_not_resolved(): void
    {
        $slot = $this->createStub(FutureSlot::class);
        $slot->method('isResolved')->willReturn(false);

        $ref = new FutureRef(ActorPath::fromString('/temp/ask-1'), $slot);

        self::assertTrue($ref->isAlive());
    }

    #[Test]
    public function is_alive_returns_false_when_resolved(): void
    {
        $slot = $this->createStub(FutureSlot::class);
        $slot->method('isResolved')->willReturn(true);

        $ref = new FutureRef(ActorPath::fromString('/temp/ask-1'), $slot);

        self::assertFalse($ref->isAlive());
    }
}
