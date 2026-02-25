<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Lifecycle;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Lifecycle\ChildFailed;
use Monadial\Nexus\Core\Lifecycle\PostRestart;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Core\Lifecycle\PreRestart;
use Monadial\Nexus\Core\Lifecycle\PreStart;
use Monadial\Nexus\Core\Lifecycle\Signal;
use Monadial\Nexus\Core\Lifecycle\Terminated;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(PreStart::class)]
#[CoversClass(PostStop::class)]
#[CoversClass(PreRestart::class)]
#[CoversClass(PostRestart::class)]
#[CoversClass(Terminated::class)]
#[CoversClass(ChildFailed::class)]
final class SignalTest extends TestCase
{
    #[Test]
    public function preStartImplementsSignal(): void
    {
        $signal = new PreStart();

        self::assertInstanceOf(Signal::class, $signal);
    }

    #[Test]
    public function postStopImplementsSignal(): void
    {
        $signal = new PostStop();

        self::assertInstanceOf(Signal::class, $signal);
    }

    #[Test]
    public function preRestartImplementsSignalAndCarriesCause(): void
    {
        $cause = new RuntimeException('test error');
        $signal = new PreRestart($cause);

        self::assertInstanceOf(Signal::class, $signal);
        self::assertSame($cause, $signal->cause);
    }

    #[Test]
    public function postRestartImplementsSignalAndCarriesCause(): void
    {
        $cause = new RuntimeException('test error');
        $signal = new PostRestart($cause);

        self::assertInstanceOf(Signal::class, $signal);
        self::assertSame($cause, $signal->cause);
    }

    #[Test]
    public function terminatedImplementsSignalAndCarriesRef(): void
    {
        $ref = $this->createActorRef('/user/test');
        $signal = new Terminated($ref);

        self::assertInstanceOf(Signal::class, $signal);
        self::assertSame($ref, $signal->ref);
    }

    #[Test]
    public function childFailedImplementsSignalAndCarriesChildAndCause(): void
    {
        $child = $this->createActorRef('/user/child');
        $cause = new RuntimeException('child error');
        $signal = new ChildFailed($child, $cause);

        self::assertInstanceOf(Signal::class, $signal);
        self::assertSame($child, $signal->child);
        self::assertSame($cause, $signal->cause);
    }

    /**
     * @return ActorRef<object>
     */
    private function createActorRef(string $path): ActorRef
    {
        $actorPath = ActorPath::fromString($path);
        $ref = $this->createStub(ActorRef::class);
        $ref->method('path')->willReturn($actorPath);

        return $ref;
    }
}
