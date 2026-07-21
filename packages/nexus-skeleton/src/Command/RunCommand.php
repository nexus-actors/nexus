<?php

declare(strict_types=1);

namespace App\Command;

use App\Kernel;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function getenv;

/**
 * @psalm-api registered in bin/console
 */
#[AsCommand('nexus:run', 'Boot the actor system and run until shutdown', aliases: ['run'])]
final class RunCommand extends Command
{
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Booting Nexus actor system…</info>');
        $appName = (string) (getenv('APP_NAME') !== false ? getenv('APP_NAME') : 'my-app');
        new Kernel($appName)->run();

        return Command::SUCCESS;
    }
}
