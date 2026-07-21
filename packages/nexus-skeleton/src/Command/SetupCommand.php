<?php

declare(strict_types=1);

namespace App\Command;

use App\Setup\Recipe;
use App\Setup\Recipes;
use Closure;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_merge;
use function array_values;
use function dirname;
use function extension_loaded;
use function file_exists;
use function file_put_contents;
use function implode;
use function sprintf;

/**
 * Interactive project setup. Runs on create-project and stays re-runnable.
 *
 * @psalm-api registered in bin/console
 */
#[AsCommand('nexus:setup', 'Configure runtime and optional modules for this project')]
final class SetupCommand extends Command
{
    private const string FIBER_RUNTIME_TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

        return static fn(): FiberRuntime => new FiberRuntime();

        PHP;

    /** @var Closure(list<string>): int */
    private readonly Closure $composerRunner;

    private readonly string $projectDir;

    /**
     * @param callable(list<string>): int|null $composerRunner
     */
    public function __construct(?callable $composerRunner = null, ?string $projectDir = null)
    {
        parent::__construct();

        $this->composerRunner = $composerRunner !== null
            ? $composerRunner(...)
            : static function (array $packages): int {
                $exit = 1;
                passthru('composer require --with-all-dependencies ' . implode(' ', $packages), $exit);

                return $exit;
            };
        $this->projectDir = $projectDir ?? dirname(__DIR__, 2);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Nexus project setup');

        $chosen = $input->isInteractive()
            ? $this->ask($io)
            : [];

        if (!$this->includesSwoole($chosen)) {
            file_put_contents($this->projectDir . '/config/packages/runtime.php', self::FIBER_RUNTIME_TEMPLATE);
        }

        $packages = [];

        foreach ($chosen as $recipe) {
            if ($recipe->experimental) {
                $io->warning(sprintf('%s is experimental, not production-ready; APIs may change.', $recipe->label));
            }

            $this->writeConfig($io, $recipe);
            $packages[] = $recipe->packages;
        }

        $packages = array_merge(...array_values($packages !== [] ? $packages : [[]]));

        if ($packages !== []) {
            $io->section('Installing packages');

            if (($this->composerRunner)($packages) !== 0) {
                $io->error('composer require failed — run it manually: composer require ' . implode(' ', $packages));

                return Command::FAILURE;
            }
        }

        $io->success('Project configured.');
        $io->listing([
            'Create your first actor:  bin/console make:actor Greeter',
            'Run the actor system:     bin/console run',
            'Re-run this wizard later: bin/console nexus:setup',
        ]);

        foreach ($chosen as $recipe) {
            $io->text(sprintf('%s docs: %s', $recipe->label, $recipe->docUrl));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<Recipe>
     */
    private function ask(SymfonyStyle $io): array
    {
        $chosen = [];

        $runtime = $io->choice('Runtime', ['fiber', 'swoole'], 'fiber');

        if ($runtime === 'swoole') {
            if (!extension_loaded('swoole')) {
                $io->warning('ext-swoole >= 6.2.1 is required for the Swoole runtime but is not loaded in this PHP.');
            }

            $chosen[] = Recipes::get('swoole');

            if ($io->confirm('Add the HTTP server (Swoole)?', false)) {
                $chosen[] = Recipes::get('http');
            }
        }

        $persistence = $io->choice(
            'Persistence store (experimental)',
            ['none', 'memory', 'dbal', 'doctrine'],
            'none',
        );

        if ($persistence !== 'none') {
            $chosen[] = Recipes::get('persistence-' . $persistence);
        }

        if ($io->choice('Observability', ['none', 'otel'], 'none') === 'otel') {
            $chosen[] = Recipes::get('otel');
        }

        if ($io->confirm('Add TCP clustering (experimental)?', false)) {
            $chosen[] = Recipes::get('cluster');
        }

        if ($io->confirm('Add the Symfony Messenger bridge (experimental)?', false)) {
            $chosen[] = Recipes::get('messenger');
        }

        return $chosen;
    }

    /**
     * @param list<Recipe> $chosen
     */
    private function includesSwoole(array $chosen): bool
    {
        foreach ($chosen as $recipe) {
            if ($recipe->key === 'swoole') {
                return true;
            }
        }

        return false;
    }

    private function writeConfig(SymfonyStyle $io, Recipe $recipe): void
    {
        if ($recipe->configFile === null || $recipe->configTemplate === null) {
            return;
        }

        $path = $this->projectDir . '/config/packages/' . $recipe->configFile;

        // runtime.php ships with the skeleton; the Swoole recipe intentionally replaces it.
        if ($recipe->configFile !== 'runtime.php' && file_exists($path)) {
            $io->text(sprintf('config/packages/%s already exists — left untouched.', $recipe->configFile));

            return;
        }

        file_put_contents($path, $recipe->configTemplate);
        $io->text(sprintf('Wrote config/packages/%s', $recipe->configFile));
    }
}
