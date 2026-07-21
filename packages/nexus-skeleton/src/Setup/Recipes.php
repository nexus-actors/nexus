<?php

declare(strict_types=1);

namespace App\Setup;

use InvalidArgumentException;

use function sprintf;

/**
 * Static module catalog for the nexus:setup wizard.
 *
 * @psalm-api consumed by SetupCommand
 */
final class Recipes
{
    private const string SWOOLE_RUNTIME_TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

        return static fn(): SwooleRuntime => new SwooleRuntime();

        PHP;

    private const string OPTIONS_TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        // Options for the %s module. Consumed by your bootstrap code:
        //   $options = require __DIR__ . '/config/packages/%s';
        // Docs: %s

        return [
        ];

        PHP;

    /**
     * @return array<string, Recipe>
     */
    public static function all(): array
    {
        return [
            'cluster' => new Recipe(
                key: 'cluster',
                label: 'TCP cluster',
                experimental: true,
                packages: ['nexus-actors/cluster-tcp'],
                configFile: 'cluster.php',
                configTemplate: self::options(
                    'TCP cluster',
                    'cluster.php',
                    'https://docs.nexusactors.com/docs/guides/clustering-over-tcp',
                ),
                docUrl: 'https://docs.nexusactors.com/docs/guides/clustering-over-tcp',
            ),
            'http' => new Recipe(
                key: 'http',
                label: 'HTTP server (Swoole)',
                experimental: false,
                packages: ['nexus-actors/http', 'nexus-actors/http-server-swoole'],
                configFile: 'http.php',
                configTemplate: self::options(
                    'HTTP server',
                    'http.php',
                    'https://docs.nexusactors.com/docs/http/overview',
                ),
                docUrl: 'https://docs.nexusactors.com/docs/http/overview',
            ),
            'messenger' => new Recipe(
                key: 'messenger',
                label: 'Symfony Messenger bridge',
                experimental: true,
                packages: ['nexus-actors/messenger'],
                configFile: 'messenger.php',
                configTemplate: self::options(
                    'Messenger bridge',
                    'messenger.php',
                    'https://docs.nexusactors.com/docs/guides/messenger-bridge',
                ),
                docUrl: 'https://docs.nexusactors.com/docs/guides/messenger-bridge',
            ),
            'otel' => new Recipe(
                key: 'otel',
                label: 'OpenTelemetry observability',
                experimental: false,
                packages: ['nexus-actors/observability-otel'],
                configFile: 'observability.php',
                configTemplate: self::options(
                    'OpenTelemetry',
                    'observability.php',
                    'https://docs.nexusactors.com/docs/observability/overview',
                ),
                docUrl: 'https://docs.nexusactors.com/docs/observability/overview',
            ),
            'persistence-dbal' => new Recipe(
                key: 'persistence-dbal',
                label: 'Persistence (Doctrine DBAL store)',
                experimental: true,
                packages: ['nexus-actors/persistence', 'nexus-actors/persistence-dbal'],
                configFile: 'persistence.php',
                configTemplate: self::options(
                    'persistence',
                    'persistence.php',
                    'https://docs.nexusactors.com/docs/persistence/overview',
                ),
                docUrl: 'https://docs.nexusactors.com/docs/persistence/overview',
            ),
            'persistence-doctrine' => new Recipe(
                key: 'persistence-doctrine',
                label: 'Persistence (Doctrine ORM store)',
                experimental: true,
                packages: ['nexus-actors/persistence', 'nexus-actors/persistence-doctrine'],
                configFile: 'persistence.php',
                configTemplate: self::options(
                    'persistence',
                    'persistence.php',
                    'https://docs.nexusactors.com/docs/persistence/overview',
                ),
                docUrl: 'https://docs.nexusactors.com/docs/persistence/overview',
            ),
            'persistence-memory' => new Recipe(
                key: 'persistence-memory',
                label: 'Persistence (in-memory store)',
                experimental: true,
                packages: ['nexus-actors/persistence'],
                configFile: 'persistence.php',
                configTemplate: self::options(
                    'persistence',
                    'persistence.php',
                    'https://docs.nexusactors.com/docs/persistence/overview',
                ),
                docUrl: 'https://docs.nexusactors.com/docs/persistence/overview',
            ),
            'swoole' => new Recipe(
                key: 'swoole',
                label: 'Swoole runtime',
                experimental: false,
                packages: ['nexus-actors/runtime-swoole'],
                configFile: 'runtime.php',
                configTemplate: self::SWOOLE_RUNTIME_TEMPLATE,
                docUrl: 'https://docs.nexusactors.com/docs/runtimes/swoole',
            ),
        ];
    }

    public static function get(string $key): Recipe
    {
        $all = self::all();

        if (!isset($all[$key])) {
            throw new InvalidArgumentException(sprintf('Unknown recipe "%s".', $key));
        }

        return $all[$key];
    }

    private static function options(string $module, string $file, string $docUrl): string
    {
        return sprintf(self::OPTIONS_TEMPLATE, $module, $file, $docUrl);
    }
}
