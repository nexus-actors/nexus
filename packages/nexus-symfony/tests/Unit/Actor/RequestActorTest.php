<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Actor;

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\StoppedBehavior;
use Monadial\Nexus\Core\Actor\UnhandledBehavior;
use Monadial\Nexus\Symfony\Actor\RequestActor;
use Monadial\Nexus\Symfony\Message\HandleHttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;

use function Swoole\Coroutine\run;

#[CoversClass(RequestActor::class)]
final class RequestActorTest extends TestCase
{
    #[Test]
    public function handlePushesResponseToChannel(): void
    {
        $symfonyRequest  = Request::create('/');
        $symfonyResponse = new Response('OK');
        $captured        = null;

        $kernel = new class ($symfonyResponse) implements HttpKernelInterface {
            public function __construct(private readonly Response $response) {}

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return $this->response;
            }
        };

        $ctx = $this->createStub(ActorContext::class);

        run(static function () use ($symfonyRequest, $kernel, $ctx, &$captured): void {
            $channel = new Channel(1);
            $actor   = new RequestActor($kernel, null);
            $result  = $actor->handle($ctx, new HandleHttpRequest($symfonyRequest, $channel));

            self::assertInstanceOf(StoppedBehavior::class, $result);
            $captured = $channel->pop(0.1);
        });

        self::assertInstanceOf(Response::class, $captured);
        self::assertSame('OK', $captured->getContent());
    }

    #[Test]
    public function handleCallsTerminateWhenKernelIsTerminable(): void
    {
        $terminateCalled = false;

        $kernel = new class ($terminateCalled) extends TerminableKernelDouble {
            /** @param bool $called passed by reference */
            public function __construct(private bool &$called) {}

            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }

            public function terminate(Request $request, Response $response): void
            {
                $this->called = true;
            }
        };

        $ctx = $this->createStub(ActorContext::class);

        run(static function () use ($kernel, $ctx): void {
            $channel = new Channel(1);
            $actor   = new RequestActor($kernel, null);
            $actor->handle($ctx, new HandleHttpRequest(Request::create('/'), $channel));
        });

        self::assertTrue($terminateCalled);
    }

    #[Test]
    public function handleReturnsUnhandledForUnknownMessage(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $ctx    = $this->createStub(ActorContext::class);

        $actor  = new RequestActor($kernel, null);
        $result = $actor->handle($ctx, new \stdClass());

        self::assertInstanceOf(UnhandledBehavior::class, $result);
    }

    #[Test]
    public function onPostStopCallsResetter(): void
    {
        $kernel   = $this->createStub(HttpKernelInterface::class);
        $resetter = $this->createMock(ResetInterface::class);
        $resetter->expects(self::once())->method('reset');

        $ctx = $this->createStub(ActorContext::class);

        $actor = new RequestActor($kernel, $resetter);
        $actor->onPostStop($ctx);
    }

    #[Test]
    public function onPostStopIsNoOpWhenResetterIsNull(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $ctx    = $this->createStub(ActorContext::class);

        $actor = new RequestActor($kernel, null);
        $actor->onPostStop($ctx); // must not throw
        self::assertTrue(true);
    }
}

/**
 * @internal Test double combining both interfaces to avoid compound/union type.
 */
abstract class TerminableKernelDouble implements HttpKernelInterface, TerminableInterface {}
