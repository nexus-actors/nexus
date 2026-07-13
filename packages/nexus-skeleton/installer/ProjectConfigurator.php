<?php

declare(strict_types=1);

namespace NexusSkeleton\Installer;

use Composer\IO\IOInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Interactive post-install configurator.
 *
 * Prompts the user (or reads env vars) to determine which runtime, HTTP,
 * persistence and tracing integrations they want, then delegates to
 * BootstrapAssembler and PackageTrimmer to tailor the project.
 */
final class ProjectConfigurator
{
    public function __construct(
        private readonly IOInterface $io,
        private readonly string $projectRoot,
    ) {}

    public function configure(): void
    {
        $this->io->write('');
        $this->io->write('<info>Welcome to Nexus Skeleton!</info>');
        $this->io->write("Let's configure your project.\n");

        $runtime = $this->askRuntime();
        $http = $this->askHttp();
        $persistence = $this->askPersistence();
        $otel = $this->askOtel();
        $cluster = $this->askCluster($runtime);

        $selections = [
            'cluster' => $cluster,
            'http' => $http,
            'otel' => $otel,
            'persistence' => $persistence,
            'runtime' => $runtime,
        ];

        $this->io->write('');
        $this->io->write('<info>Configuring your project...</info>');

        $assembler = new BootstrapAssembler($this->projectRoot);
        $assembler->assemble($selections);

        $trimmer = new PackageTrimmer($this->projectRoot);
        $trimmer->trim($selections);

        $this->removeUnneededFiles($selections);
        $this->appendReadmeNotes($selections);
        $this->removeSelf();

        $this->io->write('');
        $this->io->write('<info>Running composer update to apply changes...</info>');
        passthru('composer update --no-interaction --no-progress 2>&1');

        $this->printSummary($selections);
    }

    private function askRuntime(): string
    {
        $envVal = getenv('NEXUS_RUNTIME');

        if ($envVal !== false && in_array($envVal, ['fiber', 'swoole', 'worker-pool'], true)) {
            return $envVal;
        }

        if (!$this->io->isInteractive()) {
            return 'fiber';
        }

        $answer = $this->io->select(
            question: 'Runtime [default: fiber]',
            choices: [
                'fiber' => 'Fiber — single process, cooperative scheduling (recommended for development)',
                'swoole' => 'Swoole — coroutines + true async I/O (production)',
                'worker-pool' => 'Swoole worker pool — multi-thread, ZTS PHP + Swoole ≥ 6.2.1 with --enable-swoole-thread required',
            ],
            default: 'fiber',
        );

        return (string) $answer;
    }

    private function askHttp(): bool
    {
        $envVal = getenv('NEXUS_HTTP');

        if ($envVal !== false) {
            return $envVal === '1' || $envVal === 'true';
        }

        if (!$this->io->isInteractive()) {
            return false;
        }

        return $this->io->askConfirmation('Add HTTP server (nexus-http)? [y/N] ', false);
    }

    private function askPersistence(): string
    {
        $envVal = getenv('NEXUS_PERSISTENCE');
        $valid = ['none', 'es-dbal', 'es-doctrine', 'durable-dbal', 'durable-doctrine'];

        if ($envVal !== false && in_array($envVal, $valid, true)) {
            return $envVal;
        }

        if (!$this->io->isInteractive()) {
            return 'none';
        }

        $answer = $this->io->select(
            question: 'Persistence [default: none]',
            choices: [
                'none' => 'None — pure in-memory actors',
                'es-dbal' => 'Event sourcing via Doctrine DBAL',
                'es-doctrine' => 'Event sourcing via Doctrine ORM',
                'durable-dbal' => 'Durable state via Doctrine DBAL',
                'durable-doctrine' => 'Durable state via Doctrine ORM',
            ],
            default: 'none',
        );

        return (string) $answer;
    }

    private function askOtel(): bool
    {
        $envVal = getenv('NEXUS_OTEL');

        if ($envVal !== false) {
            return $envVal === '1' || $envVal === 'true';
        }

        if (!$this->io->isInteractive()) {
            return false;
        }

        return $this->io->askConfirmation('Add OpenTelemetry tracing? [y/N] ', false);
    }

    private function askCluster(string $runtime): bool
    {
        // The TCP cluster mesh requires the Swoole runtime (coroutine sockets).
        if ($runtime !== 'swoole') {
            return false;
        }

        $envVal = getenv('NEXUS_CLUSTER');

        if ($envVal !== false) {
            return $envVal === '1' || $envVal === 'true';
        }

        if (!$this->io->isInteractive()) {
            return false;
        }

        return $this->io->askConfirmation('Add TCP cluster mesh (nexus-cluster-tcp)? [y/N] ', false);
    }

    /** @param array<string, mixed> $selections */
    private function removeUnneededFiles(array $selections): void
    {
        $runtime = (string) $selections['runtime'];
        $http = (bool) $selections['http'];
        $persistence = (string) $selections['persistence'];

        // Remove runtime-specific docker-compose files
        if ($runtime === 'fiber') {
            $this->removeFile($this->projectRoot . '/docker-compose.swoole.yml');
            $this->removeFile($this->projectRoot . '/docker-compose.worker-pool.yml');
        } elseif ($runtime === 'swoole') {
            $this->removeFile($this->projectRoot . '/docker-compose.worker-pool.yml');
        } elseif ($runtime === 'worker-pool') {
            $this->removeFile($this->projectRoot . '/docker-compose.swoole.yml');
        }

        if ($persistence === 'none') {
            $this->removeFile($this->projectRoot . '/docker-compose.db.yml');
        }

        // Remove HTTP-related sources if not needed
        if (!$http) {
            $this->removeDirectory($this->projectRoot . '/src/Http');
            $this->removeDirectory($this->projectRoot . '/public');
        }

        // Remove persistence-related sources if not needed
        if ($persistence === 'none') {
            $this->removeDirectory($this->projectRoot . '/src/Persistence');
        }

        // Always remove installer templates after use
        $this->removeDirectory($this->projectRoot . '/templates');
    }

    /** @param array<string, mixed> $selections */
    private function appendReadmeNotes(array $selections): void
    {
        $notesFile = $this->projectRoot . '/templates/readme/notes.md';

        if (!file_exists($notesFile)) {
            return;
        }

        $readmeFile = $this->projectRoot . '/README.md';
        $notes = file_get_contents($notesFile);

        if ($notes === false || $notes === '') {
            return;
        }

        $current = file_exists($readmeFile) ? (string) file_get_contents($readmeFile) : '';
        file_put_contents($readmeFile, $current . "\n\n" . $notes);
    }

    private function removeSelf(): void
    {
        $this->removeDirectory($this->projectRoot . '/installer');

        // Remove the post-create-project-cmd script entry from composer.json
        $composerFile = $this->projectRoot . '/composer.json';

        if (!file_exists($composerFile)) {
            return;
        }

        $content = (string) file_get_contents($composerFile);

        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return;
        }

        unset($data['scripts']['post-create-project-cmd']);

        if (isset($data['scripts']) && is_array($data['scripts']) && $data['scripts'] === []) {
            unset($data['scripts']);
        }

        // Also remove the dev autoload for the installer namespace
        if (
            isset($data['autoload-dev']['psr-4'])
            && is_array($data['autoload-dev']['psr-4'])
        ) {
            unset($data['autoload-dev']['psr-4']['NexusSkeleton\\Installer\\']);
        }

        file_put_contents(
            $composerFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    private function removeFile(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($path);
    }

    /** @param array<string, mixed> $selections */
    private function printSummary(array $selections): void
    {
        $runtime = (string) $selections['runtime'];
        $http = (bool) $selections['http'];
        $persistence = (string) $selections['persistence'];

        $composeFiles = ['docker-compose.yml'];

        if ($runtime === 'swoole') {
            $composeFiles[] = 'docker-compose.swoole.yml';
        } elseif ($runtime === 'worker-pool') {
            $composeFiles[] = 'docker-compose.worker-pool.yml';
        }

        if ($persistence !== 'none') {
            $composeFiles[] = 'docker-compose.db.yml';
        }

        $fileFlags = implode(' ', array_map(static fn(string $f) => '-f ' . $f, $composeFiles));
        $composeCmd = 'docker compose ' . $fileFlags;

        $this->io->write('');
        $this->io->write('<info>✓ Your Nexus app is ready!</info>');
        $this->io->write('');
        $this->io->write('  Runtime:     ' . $runtime);
        $this->io->write('  HTTP server: ' . ($http ? 'yes' : 'no'));
        $this->io->write('  Persistence: ' . $persistence);
        $this->io->write('  Telemetry:   ' . (((bool) $selections['otel']) ? 'OpenTelemetry (set OTEL_NEXUS_ASYNC_EXPORT=1 on Swoole for actorized export)' : 'no'));
        $this->io->write('  Cluster:     ' . (((bool) ($selections['cluster'] ?? false)) ? 'TCP mesh (configure CLUSTER_* env)' : 'no'));
        $this->io->write('');
        $this->io->write('<comment>Next steps:</comment>');
        $this->io->write('  ' . $composeCmd . ' up -d');
        $this->io->write('  ' . $composeCmd . ' exec app php bootstrap.php');
        $this->io->write('  ' . $composeCmd . ' exec app vendor/bin/phpunit');
        $this->io->write('');
        $this->io->write('  Docs: https://docs.nexusactors.com');
        $this->io->write('');
    }
}
