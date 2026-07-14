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
    public function __construct(private readonly IOInterface $io, private readonly string $projectRoot) {}

    public function configure(): void
    {
        $this->io->write('');
        $this->io->write('<info>Welcome to Nexus Skeleton!</info>');
        $this->io->write("Let's configure your project.\n");

        $runtime = $this->askRuntime();
        $http = $this->askHttp();
        $persistence = $this->askPersistence();
        $otel = $this->askOtel();
        // The TCP cluster mesh requires the Swoole runtime (coroutine sockets).
        // Detect the request regardless of runtime so we can print an explicit
        // "skipped" note instead of silently dropping the selection.
        $clusterRequested = $this->askCluster($runtime);
        $cluster = $runtime === 'swoole' && $clusterRequested;
        $messenger = $this->askMessenger();

        $selections = [
            'cluster' => $cluster,
            'http' => $http,
            'messenger' => $messenger,
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

        $this->rewriteReadmeQuickStart($selections);
        $this->appendEnvExample($selections);
        $this->appendReadmeNotes($selections);
        $this->removeUnneededFiles($selections);
        $this->removeSelf();

        $this->io->write('');
        $this->io->write('<info>Running composer update to apply changes...</info>');
        passthru('composer update --no-interaction --no-progress 2>&1');

        $this->printUnsupportedNotes($selections, $runtime, $clusterRequested);
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
                'durable-dbal' => 'Durable state via Doctrine DBAL',
                'durable-doctrine' => 'Durable state via Doctrine ORM',
                'es-dbal' => 'Event sourcing via Doctrine DBAL',
                'es-doctrine' => 'Event sourcing via Doctrine ORM',
                'none' => 'None — pure in-memory actors',
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

    private function askMessenger(): bool
    {
        $envVal = getenv('NEXUS_MESSENGER');

        if ($envVal !== false) {
            return $envVal === '1' || $envVal === 'true';
        }

        if (!$this->io->isInteractive()) {
            return false;
        }

        return $this->io->askConfirmation('Add Symfony Messenger bridge (nexus-messenger)? [y/N] ', false);
    }

    /**
     * Returns whether the user *requested* the TCP cluster mesh. The caller gates
     * this to the Swoole runtime; a request under fiber/worker-pool is surfaced as
     * an explicit "skipped" note rather than being silently honoured.
     */
    private function askCluster(string $runtime): bool
    {
        $envVal = getenv('NEXUS_CLUSTER');

        if ($envVal !== false) {
            return $envVal === '1' || $envVal === 'true';
        }

        if (!$this->io->isInteractive()) {
            return false;
        }

        // The TCP cluster mesh requires the Swoole runtime (coroutine sockets),
        // so don't ask an interactive question we would only discard.
        if ($runtime !== 'swoole') {
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

        $current = file_exists($readmeFile)
            ? (string) file_get_contents($readmeFile)
            : '';
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
        if (isset($data['autoload-dev']['psr-4']) && is_array($data['autoload-dev']['psr-4'])) {
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

    /**
     * @param array<string, mixed> $selections
     * @return list<string>
     */
    private function composeFiles(array $selections): array
    {
        $runtime = (string) $selections['runtime'];
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

        return $composeFiles;
    }

    /** @param array<string, mixed> $selections */
    private function composeCommand(array $selections): string
    {
        $composeFiles = $this->composeFiles($selections);

        // A plain fiber project needs no -f flags: `docker compose up` is enough.
        if ($composeFiles === ['docker-compose.yml']) {
            return 'docker compose';
        }

        $fileFlags = implode(' ', array_map(static fn(string $f) => '-f ' . $f, $composeFiles));

        return 'docker compose ' . $fileFlags;
    }

    /**
     * Rewrites the generated README's quick-start block so the compose commands
     * reference the selection-specific stack (fiber gets plain `docker compose`,
     * swoole/worker-pool/persistence get the `-f` overlay stack).
     *
     * @param array<string, mixed> $selections
     */
    private function rewriteReadmeQuickStart(array $selections): void
    {
        $readmeFile = $this->projectRoot . '/README.md';

        if (!file_exists($readmeFile)) {
            return;
        }

        $composeCmd = $this->composeCommand($selections);

        if ($composeCmd === 'docker compose') {
            return;
        }

        $readme = (string) file_get_contents($readmeFile);
        $readme = str_replace(
            [
                'docker compose up -d',
                'docker compose exec app php bootstrap.php',
                'docker compose exec app vendor/bin/phpunit',
            ],
            [
                $composeCmd . ' up -d',
                $composeCmd . ' exec app php bootstrap.php',
                $composeCmd . ' exec app vendor/bin/phpunit',
            ],
            $readme,
        );

        file_put_contents($readmeFile, $readme);
    }

    /**
     * Appends selection-specific env blocks (CLUSTER_*, OTEL_*, DATABASE_URL)
     * to .env.example so the generated bootstrap's getenv() reads are documented.
     *
     * @param array<string, mixed> $selections
     */
    private function appendEnvExample(array $selections): void
    {
        $envFile = $this->projectRoot . '/.env.example';

        if (!file_exists($envFile)) {
            return;
        }

        $blocks = [];

        if ((string) $selections['persistence'] !== 'none') {
            $blocks[] = "# Persistence — required when persistence is enabled.\n"
                . 'DATABASE_URL=postgres://nexus:nexus@db:5432/nexus';
        }

        if ((bool) ($selections['cluster'] ?? false)) {
            $blocks[] = "# TCP cluster mesh — identity, bind/advertise endpoints and seeds.\n"
                . "CLUSTER_NAME=my-cluster\n"
                . "NODE_NAME=node-1\n"
                . "CLUSTER_BIND=0.0.0.0:7361\n"
                . "CLUSTER_ADVERTISE=127.0.0.1:7361\n"
                . '# Comma-separated host:port seeds; leave empty for a single-node cluster.'
                . "\nCLUSTER_SEEDS=";
        }

        if ((bool) ($selections['otel'] ?? false)) {
            $blocks[] = "# OpenTelemetry — OTLP exporter endpoint and service metadata.\n"
                . "OTEL_SERVICE_NAME=my-app\n"
                . "OTEL_EXPORTER_OTLP_ENDPOINT=http://localhost:4318\n"
                . '# On Swoole, run OTLP export on a dedicated actor so a slow collector never blocks actors.'
                . "\nOTEL_NEXUS_ASYNC_EXPORT=1";
        }

        if ($blocks === []) {
            return;
        }

        $current = rtrim((string) file_get_contents($envFile));
        file_put_contents($envFile, $current . "\n\n" . implode("\n\n", $blocks) . "\n");
    }

    /**
     * Prints explicit notes when a selection cannot be wired for the chosen
     * runtime, instead of silently discarding it.
     *
     * @param array<string, mixed> $selections
     */
    private function printUnsupportedNotes(array $selections, string $runtime, bool $clusterRequested): void
    {
        $notes = [];

        // The TCP cluster mesh requires the Swoole runtime.
        if ($clusterRequested && $runtime !== 'swoole') {
            $notes[] = '(TCP cluster requires the Swoole runtime — skipped)';
        }

        // The worker-pool bootstrap uses WorkerPoolApp, whose configure() does not
        // yet wire the NexusApp-based integrations. Surface each ignored selection.
        if ($runtime === 'worker-pool') {
            if ((bool) $selections['http']) {
                $notes[] = '(HTTP is not auto-wired for the worker-pool runtime — see the TODO in bootstrap.php)';
            }

            if ((bool) ($selections['otel'] ?? false)) {
                $notes[] = '(OpenTelemetry is not auto-wired for the worker-pool runtime — see the TODO in bootstrap.php)';
            }

            if ((bool) ($selections['messenger'] ?? false)) {
                $notes[] = '(Messenger is not auto-wired for the worker-pool runtime — see the TODO in bootstrap.php)';
            }

            if ((string) $selections['persistence'] !== 'none') {
                $notes[] = '(Persistence is not auto-wired for the worker-pool runtime — see the TODO in bootstrap.php)';
            }
        }

        if ($notes === []) {
            return;
        }

        $this->io->write('');

        foreach ($notes as $note) {
            $this->io->write('<comment>' . $note . '</comment>');
        }
    }

    /** @param array<string, mixed> $selections */
    private function printSummary(array $selections): void
    {
        $runtime = (string) $selections['runtime'];
        $http = (bool) $selections['http'];
        $persistence = (string) $selections['persistence'];

        $composeCmd = $this->composeCommand($selections);

        $this->io->write('');
        $this->io->write('<info>✓ Your Nexus app is ready!</info>');
        $this->io->write('');
        $this->io->write('  Runtime:     ' . $runtime);
        $this->io->write('  HTTP server: ' . ($http ? 'yes' : 'no'));
        $this->io->write('  Persistence: ' . $persistence);
        $this->io->write(
            '  Telemetry:   ' . ((bool) $selections['otel'] ? 'OpenTelemetry (set OTEL_NEXUS_ASYNC_EXPORT=1 on Swoole for actorized export)' : 'no'),
        );
        $this->io->write(
            '  Cluster:     ' . ((bool) ($selections['cluster'] ?? false) ? 'TCP mesh (configure CLUSTER_* env)' : 'no'),
        );
        $this->io->write(
            '  Messenger:   ' . ((bool) ($selections['messenger'] ?? false) ? 'Symfony Messenger bridge' : 'no'),
        );
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
