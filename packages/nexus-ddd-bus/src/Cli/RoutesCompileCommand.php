<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Cli;

use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use Monadial\Nexus\Ddd\Bus\Routing\BusBuilder;
use Monadial\Nexus\Ddd\Bus\Routing\Composite;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use function count;
use function dirname;
use function is_dir;
use function is_file;
use function sprintf;

/**
 * @psalm-api
 *
 * Symfony console command that compiles the pre-configured `BusBuilder`
 * (handlers + bindings + custom middleware) plus the routing strategy
 * into an opcache-friendly PHP snapshot. Adopters run this at deploy
 * time; production boot reads the snapshot via
 * `BusBuilder::loadCompiledFrom()` without re-running reflection.
 *
 *     bin/console ddd:routes:compile var/cache/ddd-routes.php
 *
 * The parent directory of the output path must exist — the command
 * does not auto-create it. If the file already exists, pass
 * `--overwrite` to replace it.
 */
#[AsCommand(
    name: 'ddd:routes:compile',
    description: 'Compile bus routing + handler attributes to an opcache-friendly PHP cache file',
)]
final class RoutesCompileCommand extends Command
{
    public function __construct(
        private readonly BusBuilder $builder,
        private readonly Composite $routing,
        private readonly Profile $profile,
        private readonly bool $hasValidator,
        private readonly bool $hasDecider,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('output', InputArgument::REQUIRED, 'Path to write the compiled snapshot')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite the output file if it already exists');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $path */
        $path = $input->getArgument('output');
        $overwrite = (bool) $input->getOption('overwrite');

        $parent = dirname($path);

        if (!is_dir($parent)) {
            $output->writeln(sprintf('<error>Parent directory %s does not exist.</error>', $parent));

            return self::FAILURE;
        }

        if (is_file($path) && !$overwrite) {
            $output->writeln(sprintf(
                '<error>Output file %s already exists. Re-run with --overwrite to replace it.</error>',
                $path,
            ));

            return self::FAILURE;
        }

        $this->builder->dumpCompiledTo($path, $this->profile, $this->hasValidator, $this->hasDecider, $this->routing);

        $snapshot = $this->builder->loadCompiledFrom($path);
        $output->writeln(sprintf(
            'Compiled %d handler(s) to %s.',
            count($snapshot->handlerMap),
            $path,
        ));

        return self::SUCCESS;
    }
}
