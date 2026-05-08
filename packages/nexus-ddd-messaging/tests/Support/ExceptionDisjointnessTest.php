<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Messaging\Tests\Support;

use Monadial\Nexus\Ddd\Core\Exception\DomainException;
use Monadial\Nexus\Ddd\Core\Exception\NexusDddException;
use Monadial\Nexus\Ddd\Messaging\Exception\DuplicateCommandHandlerException;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerNotFoundException;
use Monadial\Nexus\Ddd\Messaging\Exception\HandlerSignatureMismatchException;
use Monadial\Nexus\Ddd\Messaging\Exception\MessageDispatchException;
use Monadial\Nexus\Ddd\Messaging\Exception\MessageRejectedException;
use Monadial\Nexus\Ddd\Messaging\Exception\MessagingException;
use Monadial\Nexus\Ddd\Messaging\Exception\NonReplayableDeadLetterException;
use Monadial\Nexus\Ddd\Messaging\Exception\StagingClosedException;
use Monadial\Nexus\Ddd\Messaging\Exception\TerminalFailure;
use Monadial\Nexus\Ddd\Messaging\Exception\TransientFailure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Invariant pin for the three-root exception hierarchy. Extend with
 * `#[CoversNothing]` in the concrete test class under `tests/Unit/Exception/`.
 */
abstract class ExceptionDisjointnessTest extends TestCase
{
    #[Test]
    public function threeExceptionRootsExtendRuntimeExceptionDirectly(): void
    {
        $roots = [
            NexusDddException::class,
            DomainException::class,
            MessagingException::class,
        ];

        foreach ($roots as $root) {
            self::assertSame(
                RuntimeException::class,
                (new ReflectionClass($root))->getParentClass()->getName(),
                "{$root} must directly extend RuntimeException",
            );
        }
    }

    #[Test]
    public function threeExceptionRootsAreNotSubclassesOfEachOther(): void
    {
        $roots = [
            NexusDddException::class,
            DomainException::class,
            MessagingException::class,
        ];

        foreach ($roots as $a) {
            foreach ($roots as $b) {
                if ($a === $b) {
                    continue;
                }

                self::assertFalse(
                    is_subclass_of($a, $b),
                    "{$a} must not be a subclass of {$b}",
                );
            }
        }
    }

    #[Test]
    public function noConcreteMessagingExceptionImplementsBothFailureMarkers(): void
    {
        $concretes = [
            HandlerNotFoundException::class,
            DuplicateCommandHandlerException::class,
            HandlerSignatureMismatchException::class,
            MessageDispatchException::class,
            MessageRejectedException::class,
            StagingClosedException::class,
            NonReplayableDeadLetterException::class,
        ];

        foreach ($concretes as $class) {
            $exception = new $class();
            $implementsBoth = ($exception instanceof TransientFailure) && ($exception instanceof TerminalFailure);

            self::assertFalse($implementsBoth, "{$class} must not implement both TransientFailure and TerminalFailure");
        }
    }
}
