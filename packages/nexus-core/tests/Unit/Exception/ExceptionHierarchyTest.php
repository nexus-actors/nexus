<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Tests\Unit\Exception;

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorState;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Exception\ActorException;
use Monadial\Nexus\Core\Exception\ActorInitializationException;
use Monadial\Nexus\Core\Exception\ActorNameExistsException;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Core\Exception\InvalidActorPathException;
use Monadial\Nexus\Core\Exception\InvalidActorStateTransition;
use Monadial\Nexus\Core\Exception\InvalidBehaviorException;
use Monadial\Nexus\Core\Exception\InvalidMailboxConfigException;
use Monadial\Nexus\Core\Exception\MailboxClosedException;
use Monadial\Nexus\Core\Exception\MailboxException;
use Monadial\Nexus\Core\Exception\MailboxOverflowException;
use Monadial\Nexus\Core\Exception\MaxRetriesExceededException;
use Monadial\Nexus\Core\Exception\NexusException;
use Monadial\Nexus\Core\Exception\NexusLogicException;
use Monadial\Nexus\Core\Mailbox\OverflowStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NexusException::class)]
#[CoversClass(NexusLogicException::class)]
#[CoversClass(AskTimeoutException::class)]
#[CoversClass(ActorInitializationException::class)]
#[CoversClass(MailboxOverflowException::class)]
#[CoversClass(MailboxClosedException::class)]
#[CoversClass(MaxRetriesExceededException::class)]
#[CoversClass(InvalidActorPathException::class)]
#[CoversClass(ActorNameExistsException::class)]
#[CoversClass(InvalidActorStateTransition::class)]
#[CoversClass(InvalidBehaviorException::class)]
#[CoversClass(InvalidMailboxConfigException::class)]
final class ExceptionHierarchyTest extends TestCase
{
    // ── Checked exception hierarchy ──

    #[Test]
    public function nexusExceptionExtendsRuntimeException(): void
    {
        $exception = $this->createMock(NexusException::class);
        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    #[Test]
    public function actorExceptionExtendsNexusException(): void
    {
        $exception = $this->createMock(ActorException::class);
        self::assertInstanceOf(NexusException::class, $exception);
    }

    #[Test]
    public function askTimeoutExceptionCarriesContext(): void
    {
        $path = ActorPath::fromString('/user/orders');
        $timeout = Duration::seconds(5);

        $exception = new AskTimeoutException($path, $timeout);

        self::assertSame($path, $exception->target);
        self::assertSame($timeout, $exception->timeout);
        self::assertInstanceOf(ActorException::class, $exception);
        self::assertStringContainsString('/user/orders', $exception->getMessage());
    }

    #[Test]
    public function actorInitializationExceptionCarriesContext(): void
    {
        $path = ActorPath::fromString('/user/orders');

        $exception = new ActorInitializationException($path, 'constructor threw');

        self::assertSame($path, $exception->actor);
        self::assertSame('constructor threw', $exception->reason);
        self::assertInstanceOf(ActorException::class, $exception);
    }

    #[Test]
    public function mailboxExceptionExtendsNexusException(): void
    {
        $exception = $this->createMock(MailboxException::class);
        self::assertInstanceOf(NexusException::class, $exception);
    }

    #[Test]
    public function mailboxOverflowExceptionCarriesContext(): void
    {
        $path = ActorPath::fromString('/user/orders');

        $exception = new MailboxOverflowException($path, 5000, OverflowStrategy::ThrowException);

        self::assertSame($path, $exception->actor);
        self::assertSame(5000, $exception->capacity);
        self::assertSame(OverflowStrategy::ThrowException, $exception->strategy);
        self::assertInstanceOf(MailboxException::class, $exception);
    }

    #[Test]
    public function mailboxClosedExceptionCarriesContext(): void
    {
        $path = ActorPath::fromString('/user/orders');

        $exception = new MailboxClosedException($path);

        self::assertSame($path, $exception->actor);
        self::assertInstanceOf(MailboxException::class, $exception);
    }

    #[Test]
    public function maxRetriesExceededExceptionCarriesContext(): void
    {
        $path = ActorPath::fromString('/user/orders/child');
        $window = Duration::seconds(60);
        $lastFailure = new \RuntimeException('boom');

        $exception = new MaxRetriesExceededException($path, 3, $window, $lastFailure);

        self::assertSame($path, $exception->child);
        self::assertSame(3, $exception->maxRetries);
        self::assertSame($window, $exception->window);
        self::assertSame($lastFailure, $exception->lastFailure);
        self::assertInstanceOf(NexusException::class, $exception);
    }

    // ── Unchecked exception hierarchy ──

    #[Test]
    public function nexusLogicExceptionExtendsLogicException(): void
    {
        $exception = $this->createMock(NexusLogicException::class);
        self::assertInstanceOf(\LogicException::class, $exception);
    }

    #[Test]
    public function nexusLogicExceptionIsNotNexusException(): void
    {
        $exception = $this->createMock(NexusLogicException::class);
        self::assertNotInstanceOf(NexusException::class, $exception);
    }

    #[Test]
    public function invalidActorPathExceptionCarriesContext(): void
    {
        $exception = new InvalidActorPathException('bad path!');

        self::assertSame('bad path!', $exception->invalidPath);
        self::assertInstanceOf(NexusLogicException::class, $exception);
    }

    #[Test]
    public function actorNameExistsExceptionCarriesContext(): void
    {
        $parent = ActorPath::fromString('/user');

        $exception = new ActorNameExistsException($parent, 'orders');

        self::assertSame($parent, $exception->parent);
        self::assertSame('orders', $exception->duplicateName);
        self::assertInstanceOf(NexusLogicException::class, $exception);
    }

    #[Test]
    public function invalidActorStateTransitionCarriesContext(): void
    {
        $exception = new InvalidActorStateTransition(ActorState::Stopped, ActorState::Running);

        self::assertSame(ActorState::Stopped, $exception->from);
        self::assertSame(ActorState::Running, $exception->to);
        self::assertInstanceOf(NexusLogicException::class, $exception);
    }

    #[Test]
    public function invalidBehaviorExceptionIsUnchecked(): void
    {
        $exception = new InvalidBehaviorException('bad behavior');
        self::assertInstanceOf(NexusLogicException::class, $exception);
    }

    #[Test]
    public function invalidMailboxConfigExceptionIsUnchecked(): void
    {
        $exception = new InvalidMailboxConfigException('bad config');
        self::assertInstanceOf(NexusLogicException::class, $exception);
    }

    // ── Previous exception chaining ──

    #[Test]
    public function checkedExceptionsSupportPreviousChaining(): void
    {
        $cause = new \RuntimeException('root cause');
        $path = ActorPath::fromString('/user/orders');

        $exception = new AskTimeoutException($path, Duration::seconds(5), $cause);

        self::assertSame($cause, $exception->getPrevious());
    }
}
