<?php

declare(strict_types=1);

namespace Monadial\Nexus\App\Console;

use LogicException;
use Monadial\Nexus\App\NexusApp;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Duration;
use Monadial\Nexus\Core\Runtime\Runtime;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use const SIGINT;
use const SIGTERM;

/**
 * @psalm-api
 *
 * Abstract base command for Nexus actor applications.
 *
 * Handles runtime creation, actor system lifecycle, signal handling,
 * and graceful shutdown. Subclasses implement {@see buildApp()} to
 * register actors and dependencies.
 */
abstract class ActorCommand extends Command implements SignalableCommandInterface
{
    private ?ActorSystem $system = null;

    /**
     * Register actors and dependencies on the application.
     */
    abstract protected function buildApp(NexusApp $app, InputInterface $input): void;

    #[Override]
    public function getSubscribedSignals(): array
    {
        return [SIGTERM, SIGINT];
    }

    #[Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if ($this->system !== null) {
            $this->system->shutdown($this->shutdownTimeout());
        }

        return Command::SUCCESS;
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption(
            'shutdown-timeout',
            null,
            InputOption::VALUE_REQUIRED,
            'Graceful shutdown timeout in seconds',
            '5',
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = $this->createRuntime();
        $logger = $this->createLogger();

        $app = NexusApp::create($this->getName() ?? 'nexus-app');
        $this->buildApp($app, $input);

        $this->system = $app->start($runtime, $logger);
        $this->system->run();

        return Command::SUCCESS;
    }

    /**
     * Create the runtime for this command. Override to use a different
     * runtime (e.g. for testing).
     */
    protected function createRuntime(): Runtime
    {
        // Default implementation deferred to subclass — there is no
        // hard dependency on SwooleRuntime so concrete commands choose
        // the runtime appropriate for their environment.
        throw new LogicException(static::class . ' must override createRuntime() to provide a Runtime implementation.');
    }

    /**
     * Create the logger for the actor system. Override to customise
     * handlers, formatters, or log level.
     */
    protected function createLogger(): LoggerInterface
    {
        return new Logger($this->getName() ?? 'nexus', [
            new StreamHandler('php://stderr'),
        ]);
    }

    /**
     * Graceful shutdown timeout duration.
     */
    protected function shutdownTimeout(): Duration
    {
        return Duration::seconds(5);
    }
}
