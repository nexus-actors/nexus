<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\SameBehavior;
use Monadial\Nexus\Core\Actor\UnhandledBehavior;
use Monadial\Nexus\Symfony\Actor\WorkerSupervisorActor;
use Monadial\Nexus\Symfony\Message\HandleHttpRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

#[CoversClass(WorkerSupervisorActor::class)]
final class WorkerSupervisorActorTest extends TestCase
{
    #[Test]
    public function handleSpawnsRequestActorAndTellsIt(): void
    {
        $kernel  = $this->createStub(HttpKernelInterface::class);
        $channel = new Channel(1);
        $request = new HandleHttpRequest(Request::create('/'), $channel);

        $childRef = $this->createMock(ActorRef::class);
        $childRef->expects(self::once())->method('tell')->with($request);

        $ctx = $this->createMock(ActorContext::class);
        $ctx->expects(self::once())
            ->method('spawn')
            ->with(self::isInstanceOf(Props::class), self::stringContains('request-'))
            ->willReturn($childRef);

        $actor  = new WorkerSupervisorActor($kernel, null);
        $result = $actor->handle($ctx, $request);

        self::assertInstanceOf(SameBehavior::class, $result);
    }

    #[Test]
    public function handleReturnsUnhandledForUnknownMessage(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $ctx    = $this->createStub(ActorContext::class);

        $actor  = new WorkerSupervisorActor($kernel, null);
        $result = $actor->handle($ctx, new \stdClass());

        self::assertInstanceOf(UnhandledBehavior::class, $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    #[Test]
    public function eachRequestGetsDifferentName(): void
    {
        $kernel  = $this->createStub(HttpKernelInterface::class);
        $channel = new Channel(1);

        $names = [];
        $ctx   = $this->createMock(ActorContext::class);
        $ctx->method('spawn')
            ->willReturnCallback(function (Props $props, string $name) use (&$names): ActorRef {
                $names[] = $name;

                return $this->createStub(ActorRef::class);
            });

        $actor = new WorkerSupervisorActor($kernel, null);
        $msg   = new HandleHttpRequest(Request::create('/'), $channel);
        $actor->handle($ctx, $msg);
        $actor->handle($ctx, $msg);

        self::assertCount(2, $names);
        self::assertNotSame($names[0], $names[1]);
    }
}
