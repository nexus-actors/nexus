<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Unit\Exception;

use Monadial\Nexus\Ddd\Messaging\Exception\DuplicateCommandHandlerException;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerSignatureMismatchException;
use Monadial\Nexus\Ddd\Messaging\Exception\MessageDispatchException;
use Monadial\Nexus\Ddd\Messaging\Exception\MessageRejectedException;
use Monadial\Nexus\Ddd\Messaging\Exception\MessagingException;
use Monadial\Nexus\Ddd\Messaging\Exception\NonReplayableDeadLetterException;
use Monadial\Nexus\Ddd\Messaging\Exception\ReplayDispatchAttemptedException;
use Monadial\Nexus\Ddd\Messaging\Exception\StagingClosedException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HandlerNotFoundException::class)]
#[CoversClass(DuplicateCommandHandlerException::class)]
#[CoversClass(HandlerSignatureMismatchException::class)]
#[CoversClass(MessageDispatchException::class)]
#[CoversClass(MessageRejectedException::class)]
#[CoversClass(StagingClosedException::class)]
#[CoversClass(ReplayDispatchAttemptedException::class)]
#[CoversClass(NonReplayableDeadLetterException::class)]
final class ConcreteExceptionsTest extends TestCase
{
    #[Test]
    public function allConcreteExceptionsExtendMessagingExceptionAndHaveCorrectTerminalMarker(): void
    {
        $cases = [
            [HandlerNotFoundException::class, true],
            [DuplicateCommandHandlerException::class, true],
            [HandlerSignatureMismatchException::class, true],
            [MessageDispatchException::class, false],
            [MessageRejectedException::class, true],
            [StagingClosedException::class, false],
            [ReplayDispatchAttemptedException::class, true],
            [NonReplayableDeadLetterException::class, true],
        ];

        foreach ($cases as [$class, $isTerminal]) {
            $exception = new $class();

            self::assertInstanceOf(MessagingException::class, $exception, "{$class} must extend MessagingException");
            self::assertSame(
                $isTerminal,
                $exception instanceof TerminalFailure,
                "{$class} TerminalFailure marker mismatch",
            );
        }
    }
}
