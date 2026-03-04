<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Coroutine;

use Monadial\Nexus\Symfony\Coroutine\CoroutineScope;
use Monadial\Nexus\Symfony\Coroutine\CoroutineScopeListener;
use Monadial\Nexus\Symfony\Testing\MockCoroutineContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[CoversClass(CoroutineScopeListener::class)]
final class CoroutineScopeListenerTest extends TestCase
{
    #[Test]
    public function initialisesServicesOnMainRequest(): void
    {
        $service  = new stdClass();
        $context  = new MockCoroutineContext();
        $scope    = new CoroutineScope($context);
        $listener = new CoroutineScopeListener($scope, ['test.service' => static fn() => $service]);

        $listener($this->makeRequestEvent(isMain: true));

        self::assertSame($service, $scope->get('test.service'));
    }

    #[Test]
    public function skipsSubRequests(): void
    {
        $context  = new MockCoroutineContext();
        $scope    = new CoroutineScope($context);
        $listener = new CoroutineScopeListener($scope, ['test.service' => static fn() => new stdClass()]);

        $listener($this->makeRequestEvent(isMain: false));

        $this->expectException(RuntimeException::class);
        $scope->get('test.service');
    }

    private function makeRequestEvent(bool $isMain): RequestEvent
    {
        $kernel = $this->createStub(KernelInterface::class);
        $type   = $isMain
            ? HttpKernelInterface::MAIN_REQUEST
            : HttpKernelInterface::SUB_REQUEST;

        return new RequestEvent($kernel, Request::create('/'), $type);
    }
}
