<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Message;

use Monadial\Nexus\Symfony\Message\HandleHttpRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Channel;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(HandleHttpRequest::class)]
final class HandleHttpRequestTest extends TestCase
{
    #[Test]
    public function constructorAssignsProperties(): void
    {
        $request = Request::create('/');
        $channel = new Channel(1);
        $message = new HandleHttpRequest($request, $channel);

        self::assertSame($request, $message->request);
        self::assertSame($channel, $message->responseChannel);
    }
}
