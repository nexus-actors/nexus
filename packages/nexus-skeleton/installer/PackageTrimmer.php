<?php

declare(strict_types=1);

namespace NexusSkeleton\Installer;

/**
 * Rewrites composer.json to remove require entries for packages the user opted out of.
 *
 * Package removal map:
 * - No HTTP         → drop nexus-actors/http, nexus-actors/http-server-swoole
 * - Runtime fiber   → drop nexus-actors/runtime-swoole, nexus-actors/worker-pool, nexus-actors/worker-pool-swoole
 * - Runtime swoole  → drop nexus-actors/runtime-fiber, nexus-actors/worker-pool, nexus-actors/worker-pool-swoole
 * - Runtime worker-pool → drop nexus-actors/runtime-fiber (keep swoole + pool packages)
 * - Persistence none → drop nexus-actors/persistence, nexus-actors/persistence-dbal, nexus-actors/persistence-doctrine
 * - Persistence es-dbal / durable-dbal → drop nexus-actors/persistence-doctrine
 * - Persistence es-doctrine / durable-doctrine → drop nexus-actors/persistence-dbal
 */
final readonly class PackageTrimmer
{
    public function __construct(private string $projectRoot) {}

    /** @param array<string, mixed> $selections */
    public function trim(array $selections): void
    {
        $composerFile = $this->projectRoot . '/composer.json';

        if (!file_exists($composerFile)) {
            return;
        }

        $content = (string) file_get_contents($composerFile);

        /** @var array<string, mixed>|null $data */
        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['require']) || !is_array($data['require'])) {
            return;
        }

        $toRemove = $this->resolvePackagesToRemove($selections);

        foreach ($toRemove as $package) {
            unset($data['require'][$package]);
        }

        file_put_contents(
            $composerFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * @param array<string, mixed> $selections
     * @return list<string>
     */
    private function resolvePackagesToRemove(array $selections): array
    {
        $remove = [];
        $runtime = (string) $selections['runtime'];
        $http = (bool) $selections['http'];
        $persistence = (string) $selections['persistence'];
        $otel = (bool) ($selections['otel'] ?? false);
        $cluster = (bool) ($selections['cluster'] ?? false);

        if (!$otel) {
            $remove[] = 'nexus-actors/observability-otel';
        }

        if (!$cluster) {
            $remove[] = 'nexus-actors/cluster-tcp';
        }

        // HTTP
        if (!$http) {
            $remove[] = 'nexus-actors/http';
            $remove[] = 'nexus-actors/http-server-swoole';
        }

        // Runtime — remove unused runtimes
        if ($runtime === 'fiber') {
            $remove[] = 'nexus-actors/runtime-swoole';
            $remove[] = 'nexus-actors/worker-pool';
            $remove[] = 'nexus-actors/worker-pool-swoole';
            $remove[] = 'nexus-actors/http-server-swoole';
        } elseif ($runtime === 'swoole') {
            $remove[] = 'nexus-actors/runtime-fiber';
            $remove[] = 'nexus-actors/worker-pool';
            $remove[] = 'nexus-actors/worker-pool-swoole';
        } elseif ($runtime === 'worker-pool') {
            $remove[] = 'nexus-actors/runtime-fiber';
            // swoole + worker-pool packages stay
        }

        // Persistence
        if ($persistence === 'none') {
            $remove[] = 'nexus-actors/persistence';
            $remove[] = 'nexus-actors/persistence-dbal';
            $remove[] = 'nexus-actors/persistence-doctrine';
        } elseif ($persistence === 'es-dbal' || $persistence === 'durable-dbal') {
            $remove[] = 'nexus-actors/persistence-doctrine';
        } elseif ($persistence === 'es-doctrine' || $persistence === 'durable-doctrine') {
            $remove[] = 'nexus-actors/persistence-dbal';
        }

        return $remove;
    }
}
