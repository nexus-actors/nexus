<?php

declare(strict_types=1);

namespace NexusSkeleton\Installer;

/**
 * Assembles bootstrap.php from partial templates.
 *
 * Order: _header.php → runtime partial → http partial (if selected) → persistence partial (if selected)
 * Followed by the generated NexusApp::create() chain.
 */
final class BootstrapAssembler
{
    public function __construct(private readonly string $projectRoot) {}

    /** @param array<string, mixed> $selections */
    public function assemble(array $selections): void
    {
        $runtime = (string) $selections['runtime'];
        $http = (bool) $selections['http'];
        $persistence = (string) $selections['persistence'];

        $parts = [];
        $parts[] = $this->readPartial('_header.php');
        $parts[] = $this->readPartial('runtime.' . $runtime . '.php');

        $otel = (bool) ($selections['otel'] ?? false);
        $cluster = (bool) ($selections['cluster'] ?? false);

        if ($runtime !== 'worker-pool') {
            if ($http) {
                $parts[] = $this->readPartial('http.php');
            }

            if ($otel) {
                $parts[] = $this->readPartial('observability.php');
            }

            if ($cluster) {
                $parts[] = $this->readPartial('cluster.php');
            }

            if ((bool) ($selections['messenger'] ?? false)) {
                $parts[] = $this->readPartial('messenger.php');
            }

            if ($persistence !== 'none') {
                $persistenceKey = match ($persistence) {
                    'es-dbal' => 'persistence.dbal.es.php',
                    'durable-dbal' => 'persistence.dbal.ds.php',
                    'es-doctrine' => 'persistence.doctrine.es.php',
                    'durable-doctrine' => 'persistence.doctrine.ds.php',
                    default => null,
                };

                if ($persistenceKey !== null) {
                    $parts[] = $this->readPartial($persistenceKey);
                }
            }
        }

        $parts[] = $this->assembleAppChain($selections);

        $bootstrap = implode("\n", array_filter($parts, static fn(string $p): bool => $p !== ''));

        if ($runtime === 'worker-pool') {
            $bootstrap = str_replace("use Monadial\\Nexus\\App\\NexusApp;\n", '', $bootstrap);
        }

        file_put_contents($this->projectRoot . '/bootstrap.php', $bootstrap . "\n");
    }

    /** @param array<string, mixed> $selections */
    private function assembleAppChain(array $selections): string
    {
        $runtime = (string) $selections['runtime'];
        $http = (bool) $selections['http'];
        $persistence = (string) $selections['persistence'];

        if ($runtime === 'worker-pool') {
            return $this->assembleWorkerPoolChain($selections);
        }

        $otel = (bool) ($selections['otel'] ?? false);
        $cluster = (bool) ($selections['cluster'] ?? false);

        $runtimeNew = match ($runtime) {
            'fiber' => 'new FiberRuntime()',
            'swoole' => 'new SwooleRuntime()',
            default => 'new FiberRuntime()',
        };

        $lines = [];

        if ($otel) {
            $lines[] = "// OpenTelemetry: configured from OTEL_* env (endpoint, service name, sampler).";
            $lines[] = "\$observability = ObservabilityFactory::fromConfig(ObservabilityConfig::fromEnv(getenv()));";
            $lines[] = "";
        }

        if ($cluster) {
            $lines[] = "// TCP cluster mesh — identity, endpoints and seeds from CLUSTER_* env.";
            $lines[] = "// Docs: https://docs.nexusactors.com/docs/guides/clustering-over-tcp";
            $lines[] = "\$seeds = array_map(";
            $lines[] = "    static fn(string \$s) => NodeEndpoint::fromString(trim(\$s)),";
            $lines[] = "    array_filter(explode(',', (string) (getenv('CLUSTER_SEEDS') ?: ''))),";
            $lines[] = ");";
            $lines[] = "\$topology = ClusterTopology::create(";
            $lines[] = "    clusterName: getenv('CLUSTER_NAME') ?: 'my-cluster',";
            $lines[] = "    self: new NodeAddress(getenv('CLUSTER_NAME') ?: 'my-cluster', 'dc1', 'my-app', getenv('NODE_NAME') ?: 'node-1'),";
            $lines[] = "    bindEndpoint: NodeEndpoint::fromString(getenv('CLUSTER_BIND') ?: '0.0.0.0:7361'),";
            $lines[] = "    advertiseEndpoint: NodeEndpoint::fromString(getenv('CLUSTER_ADVERTISE') ?: '127.0.0.1:7361'),";
            $lines[] = "    seeds: \$seeds,";
            $lines[] = "    singleNode: \$seeds === [],";
            $lines[] = ");";
            $lines[] = "";
        }

        $lines[] = "NexusApp::create('my-app')";

        if ($otel) {
            $lines[] = "    ->withObservability(\$observability)";
        }
        $lines[] = "    ->actor('hello', Props::fromBehavior(";
        $lines[] = "        Behavior::receive(static function (\$ctx, \$msg): Behavior {";
        $lines[] = "            \$ctx->log()->info('received', ['type' => \$msg::class]);";
        $lines[] = "";
        $lines[] = "            return Behavior::same();";
        $lines[] = "        })";
        $lines[] = "    ))";

        if ($http) {
            $lines[] = "    ->onStart(static function (\$system): void {";
            $lines[] = "        \$app = HttpApp::create(\$system)";
            $lines[] = "            ->discover(__DIR__ . '/src/Http');";
            $lines[] = "        // Hand \$app->compile() to SwooleHttpServerAdapter or your server of choice.";
            $lines[] = "    })";
        }

        if ($persistence !== 'none') {
            $isDbal = str_ends_with($persistence, '-dbal') || str_ends_with($persistence, 'dbal');
            $isEs = str_starts_with($persistence, 'es-');

            if ($isDbal) {
                $lines[] = "    ->onStart(static function (\$system): void {";
                $lines[] = "        \$dsnParser  = new DsnParser(['mysql' => 'pdo_mysql', 'postgres' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']);";
                $lines[] = "        \$connection = DriverManager::getConnection(\$dsnParser->parse(\$_ENV['DATABASE_URL']));";

                if ($isEs) {
                    $lines[] = "        \$eventStore = new DbalEventStore(\$connection);";
                    $lines[] = "        \$snapshotStore = new DbalSnapshotStore(\$connection);";
                    $lines[] = "        // Pass to EventSourcedBehavior::create(...)->withEventStore(\$eventStore)->withSnapshotStore(\$snapshotStore)";
                } else {
                    $lines[] = "        \$stateStore = new DbalDurableStateStore(\$connection);";
                    $lines[] = "        // Pass to DurableStateBehavior::create(...)->withStateStore(\$stateStore)";
                }

                $lines[] = "    })";
            } else {
                $lines[] = "    ->onStart(static function (\$system): void {";
                $lines[] = "        /** @var EntityManagerInterface \$entityManager inject from your container */";

                if ($isEs) {
                    $lines[] = "        \$eventStore = new DoctrineEventStore(\$entityManager);";
                    $lines[] = "        \$snapshotStore = new DoctrineSnapshotStore(\$entityManager);";
                    $lines[] = "        // Pass to EventSourcedBehavior::create(...)->withEventStore(\$eventStore)->withSnapshotStore(\$snapshotStore)";
                } else {
                    $lines[] = "        \$stateStore = new DoctrineDurableStateStore(\$entityManager);";
                    $lines[] = "        // Pass to DurableStateBehavior::create(...)->withStateStore(\$stateStore)";
                }

                $lines[] = "    })";
            }
        }

        if ($cluster) {
            $lines[] = "    ->onStart(static function (\$system) use (\$topology): void {";
            $lines[] = "        \$node = ClusterNode::boot(\$system, \$topology);";
            $lines[] = "        // \$node->expose(\$ref) makes an actor reachable from other nodes;";
            $lines[] = "        // \$node->refFor(\$address, \$path) sends to remote actors.";
            $lines[] = "    })";
        }

        if ((bool) ($selections['messenger'] ?? false)) {
            $lines[] = "    ->onStart(static function (\$system): void {";
            $lines[] = "        // Bridge Symfony Messenger transports to actors: wire your SenderInterface /";
            $lines[] = "        // ReceiverInterface and spawn consumers via MessengerBridge::spawnReceivers().";
            $lines[] = "        // https://docs.nexusactors.com/docs/packages/messenger";
            $lines[] = "    })";
        }

        if ($otel && $runtime === 'swoole') {
            $lines[] = "    ->onStart(static function (\$system) use (\$observability): void {";
            $lines[] = "        // Actorized async telemetry export: with OTEL_NEXUS_ASYNC_EXPORT=1 all";
            $lines[] = "        // OTLP flush I/O runs on a dedicated actor, so a slow collector can";
            $lines[] = "        // never block your actors.";
            $lines[] = "        if (\$observability instanceof OtelObservability && getenv('OTEL_NEXUS_ASYNC_EXPORT') === '1') {";
            $lines[] = "            \$observability->attachExportActor(\$system);";
            $lines[] = "        }";
            $lines[] = "    })";
        }

        $lines[] = "    ->run({$runtimeNew});";

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $selections */
    private function assembleWorkerPoolChain(array $selections = []): string
    {
        $http = (bool) ($selections['http'] ?? false);
        $lines = [];
        $lines[] = "final class MyApp extends WorkerPoolApp";
        $lines[] = "{";
        $lines[] = "    protected function configure(WorkerNode \$node): void";
        $lines[] = "    {";

        if ($http) {
            $lines[] = "        // TODO: bind HttpApp inside configure() if you selected HTTP.";
            $lines[] = "        // See https://docs.nexusactors.com/docs/scaling/overview";
        }

        $lines[] = "        \$node->spawn(Props::fromBehavior(";
        $lines[] = "            Behavior::receive(static function (\$ctx, \$msg): Behavior {";
        $lines[] = "                \$ctx->log()->info('received', ['type' => \$msg::class]);";
        $lines[] = "";
        $lines[] = "                return Behavior::same();";
        $lines[] = "            })";
        $lines[] = "        ), 'hello');";
        $lines[] = "    }";
        $lines[] = "}";
        $lines[] = "";
        $lines[] = "MyApp::run(WorkerPoolConfig::withThreads(8));";

        return implode("\n", $lines);
    }

    private function readPartial(string $name): string
    {
        $path = $this->projectRoot . '/templates/bootstrap/' . $name;

        if (!file_exists($path)) {
            return '';
        }

        return rtrim((string) file_get_contents($path));
    }
}
