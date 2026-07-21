<?php

declare(strict_types=1);

namespace App\Command;

use App\Setup\Recipe;
use App\Setup\Recipes;
use Closure;
use JsonException;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_merge;
use function dirname;
use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

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

    /** @var Closure(string): bool */
    private readonly Closure $extensionLoaded;

    private readonly string $projectDir;

    /**
     * @param callable(list<string>): int|null $composerRunner
     * @param callable(string): bool|null $extensionLoaded
     */
    public function __construct(
        ?callable $composerRunner = null,
        ?string $projectDir = null,
        ?callable $extensionLoaded = null,
    ) {
        parent::__construct();

        $this->composerRunner = $composerRunner !== null
            ? $composerRunner(...)
            : self::defaultComposerRunner();
        $this->extensionLoaded = $extensionLoaded !== null
            ? $extensionLoaded(...)
            : static fn(string $extension): bool => extension_loaded($extension);
        $this->projectDir = $projectDir ?? dirname(__DIR__, 2);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Nexus project setup');

        $architecture = $input->isInteractive()
            ? (string) $io->choice(
                'Application architecture (a DDD reference architecture is planned)',
                ['minimal'],
                'minimal',
            )
            : 'minimal';
        $this->writeArchitecture($io, $architecture);

        $chosen = $input->isInteractive()
            ? $this->ask($io)
            : [];

        if (!$this->includesSwoole($chosen)) {
            $this->writeRuntimeConfig($io, self::FIBER_RUNTIME_TEMPLATE);
        }

        $packages = [];

        foreach ($chosen as $recipe) {
            if ($recipe->experimental) {
                $io->warning(sprintf('%s is experimental, not production-ready; APIs may change.', $recipe->label));
            }

            $this->writeConfig($io, $recipe);
            $packages[] = $recipe->packages;
        }

        $packages = array_merge(...($packages !== [] ? $packages : [[]]));

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

        $runtime = (string) $io->choice('Runtime', ['fiber', 'swoole'], 'fiber');

        if ($runtime === 'swoole' && !($this->extensionLoaded)('swoole')) {
            $io->warning('ext-swoole >= 6.2.1 is not loaded in this PHP — the Swoole runtime cannot run here.');
            $io->text('Install it first: https://docs.nexusactors.com/docs/runtimes/swoole');

            if (!$io->confirm('Install the Swoole packages anyway?', false)) {
                $io->text('Falling back to the Fiber runtime.');
                $runtime = 'fiber';
            }
        }

        if ($runtime === 'swoole') {
            $chosen[] = Recipes::get('swoole');

            if ($io->confirm('Add the HTTP server (Swoole)?', false)) {
                $chosen[] = Recipes::get('http');
            }
        }

        $persistence = (string) $io->choice(
            'Persistence store',
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

        // runtime.php ships with the skeleton; the Swoole recipe intentionally replaces it.
        if ($recipe->configFile === 'runtime.php') {
            $this->writeRuntimeConfig($io, $recipe->configTemplate);

            return;
        }

        $path = $this->projectDir . '/config/packages/' . $recipe->configFile;

        if (file_exists($path)) {
            $io->text(sprintf('config/packages/%s already exists — left untouched.', $recipe->configFile));

            return;
        }

        file_put_contents($path, $recipe->configTemplate);
        $io->text(sprintf('Wrote config/packages/%s', $recipe->configFile));
    }

    private function writeRuntimeConfig(SymfonyStyle $io, string $template): void
    {
        $path = $this->projectDir . '/config/packages/runtime.php';
        $existing = file_exists($path)
            ? file_get_contents($path)
            : null;

        if ($existing === $template) {
            return;
        }

        if ($existing !== null) {
            $io->warning(
                'Replacing existing config/packages/runtime.php — previous runtime configuration was overwritten.',
            );
        }

        file_put_contents($path, $template);
        $io->text('Wrote config/packages/runtime.php');
    }

    /**
     * Record the chosen application architecture so generators (make:actor)
     * know where and how to scaffold. Only 'minimal' exists today; a DDD
     * reference architecture will join as a preset.
     */
    private function writeArchitecture(SymfonyStyle $io, string $architecture): void
    {
        $path = $this->projectDir . '/composer.json';

        if (!file_exists($path)) {
            $io->text('No composer.json found — skipped recording the architecture.');

            return;
        }

        try {
            /** @var array<string, mixed> $composer */
            $composer = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $io->warning(
                sprintf('composer.json could not be parsed (%s) — architecture not recorded.', $e->getMessage()),
            );

            return;
        }

        /** @var array<string, mixed> $extra */
        $extra = $composer['extra'] ?? [];
        /** @var array<string, mixed> $nexus */
        $nexus = $extra['nexus'] ?? [];

        if (($nexus['architecture'] ?? null) === $architecture) {
            return;
        }

        $nexus['architecture'] = $architecture;
        $extra['nexus'] = $nexus;
        $composer['extra'] = $extra;

        file_put_contents(
            $path,
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        $io->text(sprintf('Recorded architecture "%s" in composer.json (extra.nexus.architecture).', $architecture));
    }

    /**
     * @return Closure(list<string>): int
     */
    private static function defaultComposerRunner(): Closure
    {
        /**
         * @param list<string> $packages
         */
        return static function (array $packages): int {
            $exit = 1;
            passthru('composer require --with-all-dependencies ' . implode(' ', $packages), $exit);

            return $exit;
        };
    }
}
