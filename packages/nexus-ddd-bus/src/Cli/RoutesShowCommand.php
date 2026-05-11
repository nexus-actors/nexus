<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Cli;

use Monadial\Nexus\Ddd\Bus\Routing\BusRegistry;
use Monadial\Nexus\Ddd\Bus\Routing\RoutingStrategy;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @psalm-api
 *
 * Symfony console command that prints the configured bus routes. Adopters
 * register an instance with their application's Symfony Console
 * Application (or the nexus-ddd-cli adapter package, when it lands).
 *
 * Two modes:
 *   - `ddd:routes:show`                  — list every registered command bus name.
 *   - `ddd:routes:show App\PlaceOrder`   — resolve the named message class to its
 *                                          bus + show which routing strategy resolved it.
 */
#[AsCommand(name: 'ddd:routes:show', description: 'Show the configured bus routes')]
final class RoutesShowCommand extends Command
{
    public function __construct(
        private readonly BusRegistry $registry,
        private readonly RoutingStrategy $strategy,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            'message-class',
            InputArgument::OPTIONAL,
            'Fully-qualified message class name to resolve. Omit to list all registered command buses.',
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $rawArgument */
        $rawArgument = $input->getArgument('message-class');

        if ($rawArgument === null) {
            $this->renderAll($output);

            return self::SUCCESS;
        }

        /** @var class-string $messageClass */
        $messageClass = $rawArgument;
        $this->renderOne($output, $messageClass);

        return self::SUCCESS;
    }

    private function renderAll(OutputInterface $output): void
    {
        $output->writeln('Registered command buses:');

        foreach ($this->registry->commandNames() as $name) {
            $output->writeln(sprintf('  %s', $name));
        }
    }

    /** @param class-string $messageClass */
    private function renderOne(OutputInterface $output, string $messageClass): void
    {
        $resolution = $this->strategy->resolve($messageClass)->getUnsafe();

        $output->writeln(sprintf(
            '%s → bus `%s` (resolved by %s)',
            $messageClass,
            $resolution->busName,
            $resolution->displayName(),
        ));
    }
}
