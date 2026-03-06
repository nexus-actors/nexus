<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Scheduler;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\SameBehavior;
use Monadial\Nexus\Core\Actor\UnhandledBehavior;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Cancellable;
use Monadial\Nexus\Symfony\Scheduler\CancelSchedule;
use Monadial\Nexus\Symfony\Scheduler\RegisterSchedule;
use Monadial\Nexus\Symfony\Scheduler\ScheduleEntry;
use Monadial\Nexus\Symfony\Scheduler\SchedulerActor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchedulerActor::class)]
final class SchedulerActorTest extends TestCase
{
    #[Test]
    public function registerScheduleSchedulesRepeatingTimer(): void
    {
        $cancellable = $this->createStub(Cancellable::class);

        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::once())
            ->method('scheduleRepeatedly')
            ->with(
                self::isInstanceOf(Duration::class),
                self::isInstanceOf(Duration::class),
                self::isCallable(),
            )
            ->willReturn($cancellable);

        $actor  = new SchedulerActor();
        $entry  = new ScheduleEntry('my-task', 60, static fn() => null);
        $result = $actor->handle($ctx, new RegisterSchedule($entry));

        self::assertInstanceOf(SameBehavior::class, $result);
    }

    #[Test]
    public function cancelScheduleCancelsTimer(): void
    {
        $cancellable = $this->createMock(Cancellable::class);
        $cancellable->expects(self::once())->method('cancel');

        $ctx = $this->createStub(ActorContext::class);
        $ctx->method('scheduleRepeatedly')->willReturn($cancellable);

        $actor = new SchedulerActor();
        $entry = new ScheduleEntry('my-task', 60, static fn() => null);
        $actor->handle($ctx, new RegisterSchedule($entry));
        $result = $actor->handle($ctx, new CancelSchedule('my-task'));

        self::assertInstanceOf(SameBehavior::class, $result);
    }

    #[Test]
    public function cancelNonExistentScheduleIsNoop(): void
    {
        $ctx = $this->createStub(ActorContext::class);

        $actor  = new SchedulerActor();
        $result = $actor->handle($ctx, new CancelSchedule('ghost'));

        self::assertInstanceOf(SameBehavior::class, $result);
    }

    #[Test]
    public function unknownMessageReturnsUnhandled(): void
    {
        $ctx    = $this->createStub(ActorContext::class);
        $actor  = new SchedulerActor();
        $result = $actor->handle($ctx, new \stdClass());

        self::assertInstanceOf(UnhandledBehavior::class, $result);
    }
}
