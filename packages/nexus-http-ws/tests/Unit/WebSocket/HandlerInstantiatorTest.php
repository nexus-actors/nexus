<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket;

use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\ArrayContainer;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\EchoHandler;
use Monadial\Nexus\Http\Ws\Tests\Unit\WebSocket\Support\InMemoryWebSocketContext;
use Monadial\Nexus\Http\Ws\WebSocket\Attribute\FromContext;
use Monadial\Nexus\Http\Ws\WebSocket\HandlerInstantiator;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketFrame;
use Monadial\Nexus\Http\Ws\WebSocket\WebSocketHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversClass(HandlerInstantiator::class)]
final class HandlerInstantiatorTest extends TestCase
{
    #[Test]
    public function instantiates_handler_with_from_context_and_container_resolved_params(): void
    {
        $log = new NullLogger();
        $i = new HandlerInstantiator(new ArrayContainer([LoggerInterface::class => $log]));
        $ctx = new InMemoryWebSocketContext(1);

        $h = $i->instantiate(EchoHandler::class, $ctx);

        self::assertInstanceOf(EchoHandler::class, $h);
        self::assertSame($ctx, $h->ctx);
        self::assertSame($log, $h->log);
    }

    #[Test]
    public function rejects_from_context_on_wrong_param_type(): void
    {
        $bad = new class extends WebSocketHandler {
            public function __construct(#[FromContext] public readonly string $wrong = '') {}

            public function onMessage(WebSocketFrame $frame): void
            {
                // intentionally empty — only testing constructor injection failure
            }
        };

        $i = new HandlerInstantiator(new ArrayContainer());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FromContext');

        $i->instantiate($bad::class, new InMemoryWebSocketContext(1));
    }
}
