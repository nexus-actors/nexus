<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\WorkerPool;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(name: 'nexus:consume', description: 'Run Messenger consumer in Nexus Swoole worker pool')]
final class NexusConsumeCommand extends Command
{
    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('transport', InputArgument::REQUIRED, 'Messenger transport name')
            ->addOption('workers', 'w', InputOption::VALUE_REQUIRED, 'Number of worker threads', '4');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $transport   = (string) $input->getArgument('transport');
        $workerCount = (int) $input->getOption('workers');

        $output->writeln(sprintf(
            '<info>Starting nexus:consume — transport: %s, workers: %d</info>',
            $transport,
            $workerCount,
        ));

        NexusSymfonyWorkerApp::run(
            kernel: $this->kernel,
            transport: $transport,
            workerCount: $workerCount,
        );

        return Command::SUCCESS;
    }
}
