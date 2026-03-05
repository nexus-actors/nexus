<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Tests\Unit\Logging;

use DateTimeImmutable;
use Monadial\Nexus\Symfony\Logging\AsyncMonologHandler;
use Monolog\Handler\HandlerInterface;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsyncMonologHandler::class)]
final class AsyncMonologHandlerTest extends TestCase
{
    #[Test]
    public function isHandlingRespectsMinimumLevel(): void
    {
        $inner   = $this->createStub(HandlerInterface::class);
        $handler = new AsyncMonologHandler($inner, 0, Level::Warning);

        self::assertFalse($handler->isHandling($this->makeRecord(Level::Info)));
        self::assertTrue($handler->isHandling($this->makeRecord(Level::Warning)));
        self::assertTrue($handler->isHandling($this->makeRecord(Level::Error)));
    }

    #[Test]
    public function handleFallsThroughToInnerWhenNotInCoroutineContext(): void
    {
        $record  = $this->makeRecord();
        $inner   = $this->createMock(HandlerInterface::class);
        $inner->expects($this->once())->method('handle')->with($record)->willReturn(true);

        $handler = new AsyncMonologHandler($inner);

        // Not in a Swoole coroutine (Coroutine::getUid() returns -1 or 0)
        $handler->handle($record);
    }

    #[Test]
    public function isStartedReturnsFalseInitially(): void
    {
        $inner   = $this->createStub(HandlerInterface::class);
        $handler = new AsyncMonologHandler($inner);

        self::assertFalse($handler->isStarted());
    }

    #[Test]
    public function handleBatchDelegatesToHandle(): void
    {
        $records = [$this->makeRecord(), $this->makeRecord(Level::Error)];
        $inner   = $this->createMock(HandlerInterface::class);
        $inner->expects($this->exactly(2))->method('handle')->willReturn(true);

        $handler = new AsyncMonologHandler($inner);
        $handler->handleBatch($records);
    }

    #[Test]
    public function closeCallsInnerClose(): void
    {
        $inner = $this->createMock(HandlerInterface::class);
        $inner->expects($this->once())->method('close');

        $handler = new AsyncMonologHandler($inner);
        $handler->close();
    }

    #[Test]
    public function stopIsNoopWhenNotStarted(): void
    {
        $inner   = $this->createStub(HandlerInterface::class);
        $handler = new AsyncMonologHandler($inner);

        // Must not throw
        $handler->stop();

        self::assertFalse($handler->isStarted());
    }

    private function makeRecord(Level $level = Level::Info): LogRecord
    {
        return new LogRecord(
            datetime: new DateTimeImmutable(),
            channel: 'test',
            level: $level,
            message: 'test message',
        );
    }
}
