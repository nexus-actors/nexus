<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console\Swoole\Tests\Unit;

use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumeCommand;
use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumerBootstrap;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

#[CoversClass(ThreadedConsumeCommand::class)]
final class ThreadedConsumeCommandTest extends TestCase
{
    #[Test]
    public function rejectsClassThatDoesNotImplementThreadedConsumerBootstrap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must implement');

        new ThreadedConsumeCommand(stdClass::class);
    }

    #[Test]
    public function acceptsValidBootstrapClassString(): void
    {
        $command = new ThreadedConsumeCommand(MinimalBootstrap::class);

        self::assertSame('nexus:messenger:consume-threads', $command->getName());
    }

    #[Test]
    public function commandDefinesExpectedOptions(): void
    {
        $command    = new ThreadedConsumeCommand(MinimalBootstrap::class);
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('threads'));
        self::assertTrue($definition->hasOption('receivers'));
        self::assertTrue($definition->hasOption('limit'));
        self::assertTrue($definition->hasOption('memory-limit'));
        self::assertTrue($definition->hasOption('time-limit'));
        self::assertTrue($definition->hasOption('poll-interval'));
        self::assertTrue($definition->hasOption('dead-letters'));
    }

    #[Test]
    public function rejectsZeroLimitWithoutStarting(): void
    {
        $command = new ThreadedConsumeCommand(MinimalBootstrap::class);
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute(['--limit' => '0']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--limit must be a positive integer', $tester->getDisplay());
    }

    #[Test]
    public function rejectsNonNumericThreadsWithoutStarting(): void
    {
        $command = new ThreadedConsumeCommand(MinimalBootstrap::class);
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute(['--threads' => 'abc']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--threads must be a positive integer', $tester->getDisplay());
    }

    #[Test]
    public function rejectsZeroMemoryLimitWithoutStarting(): void
    {
        $command = new ThreadedConsumeCommand(MinimalBootstrap::class);
        $tester  = new CommandTester($command);

        $exitCode = $tester->execute(['--memory-limit' => '0']);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('greater than 0', $tester->getDisplay());
    }

    #[Test]
    public function threadsOptionDefaultsToTwo(): void
    {
        $command = new ThreadedConsumeCommand(MinimalBootstrap::class);
        $option  = $command->getDefinition()->getOption('threads');

        self::assertSame('2', (string) $option->getDefault());
    }

    #[Test]
    public function pollIntervalOptionDefaultsToOneHundred(): void
    {
        $command = new ThreadedConsumeCommand(MinimalBootstrap::class);
        $option  = $command->getDefinition()->getOption('poll-interval');

        self::assertSame('100', (string) $option->getDefault());
    }
}

/**
 * Minimal fixture implementing the interface — kept inline so the test is
 * self-contained without importing integration-test support classes.
 *
 * @psalm-api
 */
final class MinimalBootstrap implements ThreadedConsumerBootstrap
{
    public function setup(ActorSystem $system): MessageRouter
    {
        return new MapMessageRouter([]);
    }

    public function receiver(): ReceiverInterface
    {
        return new InMemoryTransport();
    }
}
