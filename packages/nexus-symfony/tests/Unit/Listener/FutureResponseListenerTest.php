<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Listener;

use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Async\FutureSlot;
use Monadial\Nexus\Symfony\Listener\FutureResponseListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

#[CoversClass(FutureResponseListener::class)]
final class FutureResponseListenerTest extends TestCase
{
    #[Test]
    public function setsResponseFromResolvedFuture(): void
    {
        $expected = new JsonResponse(['ok' => true]);
        $slot     = $this->createMock(FutureSlot::class);
        $slot->expects(self::once())->method('await')->willReturn($expected);

        $kernel = $this->createStub(HttpKernelInterface::class);
        $event  = new ViewEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, new Future($slot));

        (new FutureResponseListener())($event);

        self::assertSame($expected, $event->getResponse());
    }

    #[Test]
    public function ignoresNonFutureControllerResult(): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event  = new ViewEvent($kernel, Request::create('/'), HttpKernelInterface::MAIN_REQUEST, ['not-a-future']);

        (new FutureResponseListener())($event);

        self::assertNull($event->getResponse());
    }
}
