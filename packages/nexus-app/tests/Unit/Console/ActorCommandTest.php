<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Tests\Unit\Console;

use Monadial\Nexus\App\Console\ActorCommand;
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Tests\Support\TestClock;
use Monadial\Nexus\Core\Tests\Support\TestRuntime;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;

use const SIGINT;
use const SIGTERM;

#[CoversClass(ActorCommand::class)]
#[RequiresPhpExtension('pcntl')]
final class ActorCommandTest extends TestCase
{
    #[Test]
    public function buildApp_is_called_with_parsed_input(): void
    {
        $buildAppCalled = false;
        $capturedInput = null;

        $runtime = new TestRuntime(new TestClock());

        $command = new class ($runtime, $buildAppCalled, $capturedInput) extends ActorCommand {
            public function __construct(
                private readonly Runtime $testRuntime,
                private bool &$buildAppCalled,
                private ?InputInterface &$capturedInput,
            ) {
                parent::__construct('test:command');
            }

            #[Override]
            protected function createRuntime(): Runtime
            {
                return $this->testRuntime;
            }

            #[Override]
            protected function buildApp(NexusApp $app, InputInterface $input): void
            {
                $this->buildAppCalled = true;
                $this->capturedInput = $input;

                /** @psalm-suppress InvalidArgument, UnusedClosureParam */
                $app->actor('worker', Props::fromBehavior(Behavior::receive(
                    static fn($ctx, $msg) => Behavior::same(),
                )));
            }
        };

        $input = new ArrayInput([], $command->getDefinition());
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        self::assertTrue($buildAppCalled);
        self::assertNotNull($capturedInput);
        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function signal_handling_triggers_shutdown(): void
    {
        $runtime = new TestRuntime(new TestClock());

        $command = new class ($runtime) extends ActorCommand {
            public function __construct(private readonly Runtime $testRuntime)
            {
                parent::__construct('test:signal');
            }

            #[Override]
            protected function createRuntime(): Runtime
            {
                return $this->testRuntime;
            }

            #[Override]
            protected function buildApp(NexusApp $app, InputInterface $input): void
            {
                /** @psalm-suppress InvalidArgument, UnusedClosureParam */
                $app->actor('worker', Props::fromBehavior(Behavior::receive(
                    static fn($ctx, $msg) => Behavior::same(),
                )));
            }
        };

        $input = new ArrayInput([], $command->getDefinition());
        $output = new NullOutput();

        // Run the command to initialize the system
        $command->run($input, $output);

        // Simulate SIGTERM
        $result = $command->handleSignal(SIGTERM, 0);

        self::assertSame(Command::SUCCESS, $result);
        // Runtime should no longer be running after shutdown
        self::assertFalse($runtime->isRunning());
    }

    #[Test]
    public function subscribed_signals_include_sigterm_and_sigint(): void
    {
        $runtime = new TestRuntime(new TestClock());

        $command = new class ($runtime) extends ActorCommand {
            public function __construct(private readonly Runtime $testRuntime)
            {
                parent::__construct('test:signals');
            }

            #[Override]
            protected function createRuntime(): Runtime
            {
                return $this->testRuntime;
            }

            #[Override]
            protected function buildApp(NexusApp $app, InputInterface $input): void
            {
                // no actors needed for this test
            }
        };

        $signals = $command->getSubscribedSignals();

        self::assertContains(SIGTERM, $signals);
        self::assertContains(SIGINT, $signals);
    }

    #[Test]
    public function exit_code_is_success_on_clean_run(): void
    {
        $runtime = new TestRuntime(new TestClock());

        $command = new class ($runtime) extends ActorCommand {
            public function __construct(private readonly Runtime $testRuntime)
            {
                parent::__construct('test:clean');
            }

            #[Override]
            protected function createRuntime(): Runtime
            {
                return $this->testRuntime;
            }

            #[Override]
            protected function buildApp(NexusApp $app, InputInterface $input): void
            {
                /** @psalm-suppress InvalidArgument, UnusedClosureParam */
                $app->actor('worker', Props::fromBehavior(Behavior::receive(
                    static fn($ctx, $msg) => Behavior::same(),
                )));
            }
        };

        $input = new ArrayInput([], $command->getDefinition());
        $output = new NullOutput();

        $exitCode = $command->run($input, $output);

        self::assertSame(Command::SUCCESS, $exitCode);
    }
}
