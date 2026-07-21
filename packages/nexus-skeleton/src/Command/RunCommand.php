<?php

declare(strict_types=1);

namespace App\Command;

use App\Kernel;
use App\Message\Greet;
use Override;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;

use function getenv;
use function sprintf;

/**
 * @psalm-api registered in bin/console
 */
#[AsCommand('nexus:run', 'Boot the actor system and run until shutdown', aliases: ['run'])]
final class RunCommand extends Command
{
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // bin/console loads .env via Symfony Dotenv with usePutenv(), so getenv() sees it.
        $appName = (string) (getenv('APP_NAME') !== false ? getenv('APP_NAME') : 'my-app');
        $output->writeln(sprintf('<info>Booting Nexus actor system… (app: %s)</info>', $appName));

        // Actor $ctx->log() calls land on the console; info is visible without -v.
        $logger = new ConsoleLogger($output, [LogLevel::INFO => OutputInterface::VERBOSITY_NORMAL]);
        $kernel = new Kernel($appName, $logger);
        $system = $kernel->boot();

        foreach ($kernel->spawnedActors() as $name => $class) {
            $output->writeln(sprintf(' <info>✓</info> %s — %s', $name, $class));
        }

        // Greet ourselves so the first run shows a message flowing through an actor.
        $kernel->ref('greeter')?->tell(new Greet('Nexus'));

        $system->run();

        return Command::SUCCESS;
    }
}
