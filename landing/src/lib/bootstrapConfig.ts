// landing/src/lib/bootstrapConfig.ts
// Pure functions: take wizard selections, return four artifact strings.

export type Runtime = 'fiber' | 'swoole' | 'worker-pool';
export type Persistence = 'none' | 'es-dbal' | 'es-doctrine' | 'ds-dbal' | 'ds-doctrine';

export interface Selections {
  runtime: Runtime;
  http: boolean;
  websockets: boolean;
  doctrine: boolean;
  otel: boolean;
  cluster: boolean;
  messenger: boolean;
  persistence: Persistence;
}

export interface Artifacts {
  composer: string;
  bootstrap: string;
  compose: string;
  readme: string;
}

// ── Package lists ────────────────────────────────────────────────────────────

function getPackages(s: Selections): { prod: string[]; dev: string[] } {
  const prod: string[] = [
    'nexus-actors/core',
    'nexus-actors/app',
  ];

  // Runtime
  if (s.runtime === 'fiber') {
    prod.push('nexus-actors/runtime-fiber');
  } else if (s.runtime === 'swoole') {
    prod.push('nexus-actors/runtime-swoole');
  } else {
    prod.push('nexus-actors/runtime-swoole');
    prod.push('nexus-actors/worker-pool');
    prod.push('nexus-actors/worker-pool-swoole');
  }

  // HTTP
  if (s.http || s.websockets) {
    prod.push('nexus-actors/http');
    if (s.runtime === 'swoole' || s.runtime === 'worker-pool') {
      prod.push('nexus-actors/http-server-swoole');
    }
  }

  // WebSockets (separate package from HTTP)
  if (s.websockets) {
    prod.push('nexus-actors/http-ws');
  }

  // Persistence
  if (s.persistence === 'es-dbal' || s.persistence === 'ds-dbal') {
    prod.push('nexus-actors/persistence');
    prod.push('nexus-actors/persistence-dbal');
  } else if (s.persistence === 'es-doctrine' || s.persistence === 'ds-doctrine') {
    prod.push('nexus-actors/persistence');
    prod.push('nexus-actors/persistence-doctrine');
  } else if (s.doctrine) {
    // Doctrine ORM bridge only (no persistence chosen but doctrine checkbox ticked)
    prod.push('nexus-actors/doctrine-orm');
    prod.push('nexus-actors/doctrine-dbal');
  }

  if (s.otel) {
    prod.push('nexus-actors/observability');
    prod.push('nexus-actors/observability-otel');
  }

  // TCP cluster mesh — Swoole runtime only (coroutine sockets).
  if (s.cluster && (s.runtime === 'swoole' || s.runtime === 'worker-pool')) {
    prod.push('nexus-actors/cluster');
    prod.push('nexus-actors/cluster-tcp');
  }

  // Symfony Messenger bridge — publish/consume actor messages over a broker.
  if (s.messenger) {
    prod.push('nexus-actors/messenger');
    prod.push('symfony/messenger');
  }

  const dev = ['nexus-actors/psalm', 'vimeo/psalm'];

  return { prod, dev };
}

// ── Composer command ─────────────────────────────────────────────────────────

function buildComposer(s: Selections): string {
  const { prod, dev } = getPackages(s);
  return `composer require ${prod.join(' ')}\ncomposer require --dev ${dev.join(' ')}`;
}

// ── bootstrap.php ────────────────────────────────────────────────────────────

function buildBootstrap(s: Selections): string {
  // Worker-pool uses WorkerPoolApp — a mutually exclusive entry point from NexusApp.
  if (s.runtime === 'worker-pool') {
    return buildWorkerPoolBootstrap(s);
  }

  const lines: string[] = [];

  // Runtime import
  let runtimeImport = '';
  let runtimeClass = '';
  if (s.runtime === 'fiber') {
    runtimeImport = 'use Monadial\\Nexus\\Runtime\\Fiber\\FiberRuntime;';
    runtimeClass = 'FiberRuntime';
  } else {
    runtimeImport = 'use Monadial\\Nexus\\Runtime\\Swoole\\SwooleRuntime;';
    runtimeClass = 'SwooleRuntime';
  }

  // HTTP import
  const httpImport = s.http || s.websockets ? 'use Monadial\\Nexus\\Http\\Dsl\\HttpApp;' : '';
  const wsImport = s.websockets ? 'use Monadial\\Nexus\\Http\\Ws\\WsApplication;' : '';
  const clusterEnabled = s.cluster && s.runtime === 'swoole';
  const otelImport = s.otel
    ? [
        'use Monadial\\Nexus\\Observability\\Config\\ObservabilityConfig;',
        'use Monadial\\Nexus\\Observability\\Otel\\ObservabilityFactory;',
        'use Monadial\\Nexus\\Observability\\Otel\\OtelObservability;',
      ].join('\n')
    : '';
  const clusterImport = clusterEnabled
    ? [
        'use Monadial\\Nexus\\Cluster\\NodeAddress;',
        'use Monadial\\Nexus\\Cluster\\Tcp\\ClusterNode;',
        'use Monadial\\Nexus\\Cluster\\Tcp\\ClusterTopology;',
        'use Monadial\\Nexus\\Cluster\\Tcp\\NodeEndpoint;',
      ].join('\n')
    : '';
  const messengerImport = s.messenger ? 'use Monadial\\Nexus\\Messenger\\MessengerBridge;' : '';

  // Persistence imports
  let persistenceImport = '';
  if (s.persistence === 'es-dbal' || s.persistence === 'ds-dbal') {
    const isEs = s.persistence === 'es-dbal';
    persistenceImport = [
      'use Doctrine\\DBAL\\DriverManager;',
      'use Doctrine\\DBAL\\Tools\\DsnParser;',
      isEs ? 'use Monadial\\Nexus\\Persistence\\Dbal\\DbalEventStore;' : 'use Monadial\\Nexus\\Persistence\\Dbal\\DbalDurableStateStore;',
      isEs ? 'use Monadial\\Nexus\\Persistence\\Dbal\\DbalSnapshotStore;' : '',
    ].filter(Boolean).join('\n');
  } else if (s.persistence === 'es-doctrine' || s.persistence === 'ds-doctrine') {
    const isEs = s.persistence === 'es-doctrine';
    persistenceImport = [
      'use Doctrine\\ORM\\EntityManagerInterface;',
      isEs ? 'use Monadial\\Nexus\\Persistence\\Doctrine\\DoctrineEventStore;' : 'use Monadial\\Nexus\\Persistence\\Doctrine\\DoctrineDurableStateStore;',
      isEs ? 'use Monadial\\Nexus\\Persistence\\Doctrine\\DoctrineSnapshotStore;' : '',
    ].filter(Boolean).join('\n');
  }

  // Collect extra use statements
  const extraImports = [runtimeImport, httpImport, wsImport, otelImport, clusterImport, messengerImport, persistenceImport]
    .filter(Boolean)
    .join('\n');

  // onStart blocks
  const onStartBlocks: string[] = [];

  if (s.http) {
    onStartBlocks.push(
      `    ->onStart(static function ($system): void {\n` +
      `        $app = HttpApp::create($system)\n` +
      `            ->discover(__DIR__ . '/src/Http');\n` +
      `        // Hand $app->compile() to SwooleHttpServerAdapter or your server of choice.\n` +
      `    })`,
    );
  }

  if (clusterEnabled) {
    onStartBlocks.push(
      `    ->onStart(static function ($system) use ($topology): void {\n` +
      `        $node = ClusterNode::boot($system, $topology);\n` +
      `        // $node->expose($ref) makes an actor reachable from other nodes;\n` +
      `        // $node->refFor($address, $path) sends to remote actors.\n` +
      `    })`,
    );
  }

  if (s.messenger) {
    onStartBlocks.push(
      `    ->onStart(static function ($system): void {\n` +
      `        // Bridge Symfony Messenger transports to actors. Wire your SenderInterface /\n` +
      `        // ReceiverInterface here; see MessengerBridge::spawnReceivers() / gateway().\n` +
      `        // https://docs.nexusactors.com/docs/packages/messenger\n` +
      `    })`,
    );
  }

  if (s.otel && s.runtime === 'swoole') {
    onStartBlocks.push(
      `    ->onStart(static function ($system) use ($observability): void {\n` +
      `        // Actorized async export: with OTEL_NEXUS_ASYNC_EXPORT=1 all OTLP flush I/O\n` +
      `        // runs on a dedicated actor, so a slow collector never blocks your actors.\n` +
      `        if ($observability instanceof OtelObservability && getenv('OTEL_NEXUS_ASYNC_EXPORT') === '1') {\n` +
      `            $observability->attachExportActor($system);\n` +
      `        }\n` +
      `    })`,
    );
  }

  if (s.persistence !== 'none') {
    const isDbal = s.persistence === 'es-dbal' || s.persistence === 'ds-dbal';
    const isEs = s.persistence === 'es-dbal' || s.persistence === 'es-doctrine';

    if (isDbal) {
      const storeLines = isEs
        ? [
            `        $eventStore = new DbalEventStore($connection);`,
            `        $snapshotStore = new DbalSnapshotStore($connection);`,
            `        // Pass to EventSourcedBehavior::create(...)->withEventStore($eventStore)->withSnapshotStore($snapshotStore)`,
          ]
        : [
            `        $stateStore = new DbalDurableStateStore($connection);`,
            `        // Pass to DurableStateBehavior::create(...)->withStateStore($stateStore)`,
          ];
      onStartBlocks.push(
        `    ->onStart(static function ($system): void {\n` +
        `        $dsnParser = new DsnParser(['mysql' => 'pdo_mysql', 'postgres' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']);\n` +
        `        $connection = DriverManager::getConnection($dsnParser->parse($_ENV['DATABASE_URL']));\n` +
        storeLines.join('\n') + '\n' +
        `    })`,
      );
    } else {
      const storeLines = isEs
        ? [
            `        $eventStore = new DoctrineEventStore($entityManager);`,
            `        $snapshotStore = new DoctrineSnapshotStore($entityManager);`,
            `        // Pass to EventSourcedBehavior::create(...)->withEventStore($eventStore)->withSnapshotStore($snapshotStore)`,
          ]
        : [
            `        $stateStore = new DoctrineDurableStateStore($entityManager);`,
            `        // Pass to DurableStateBehavior::create(...)->withStateStore($stateStore)`,
          ];
      onStartBlocks.push(
        `    ->onStart(static function ($system): void {\n` +
        `        /** @var EntityManagerInterface $entityManager inject from your container */\n` +
        storeLines.join('\n') + '\n' +
        `    })`,
      );
    }
  }

  lines.push(`<?php`);
  lines.push(``);
  lines.push(`declare(strict_types=1);`);
  lines.push(``);
  lines.push(`require __DIR__ . '/vendor/autoload.php';`);
  lines.push(``);
  lines.push(`use Monadial\\Nexus\\App\\NexusApp;`);
  lines.push(`use Monadial\\Nexus\\Core\\Actor\\Behavior;`);
  lines.push(`use Monadial\\Nexus\\Core\\Actor\\Props;`);
  lines.push(`use Monadial\\Nexus\\Runtime\\Duration;`);
  if (extraImports) {
    lines.push(extraImports);
  }
  lines.push(``);

  if (s.otel) {
    lines.push(`// OpenTelemetry: built from OTEL_* env (endpoint, service name, sampler).`);
    lines.push(`$observability = ObservabilityFactory::fromConfig(ObservabilityConfig::fromEnv(getenv()));`);
    lines.push(``);
  }

  if (clusterEnabled) {
    lines.push(`// TCP cluster mesh — identity, endpoints and seeds from CLUSTER_* env.`);
    lines.push(`$seeds = array_map(`);
    lines.push(`    static fn(string $s) => NodeEndpoint::fromString(trim($s)),`);
    lines.push(`    array_filter(explode(',', (string) (getenv('CLUSTER_SEEDS') ?: ''))),`);
    lines.push(`);`);
    lines.push(`$topology = ClusterTopology::create(`);
    lines.push(`    clusterName: getenv('CLUSTER_NAME') ?: 'my-cluster',`);
    lines.push(`    self: new NodeAddress(getenv('CLUSTER_NAME') ?: 'my-cluster', 'dc1', 'my-app', getenv('NODE_NAME') ?: 'node-1'),`);
    lines.push(`    bindEndpoint: NodeEndpoint::fromString(getenv('CLUSTER_BIND') ?: '0.0.0.0:7361'),`);
    lines.push(`    advertiseEndpoint: NodeEndpoint::fromString(getenv('CLUSTER_ADVERTISE') ?: '127.0.0.1:7361'),`);
    lines.push(`    seeds: $seeds,`);
    lines.push(`    singleNode: $seeds === [],`);
    lines.push(`);`);
    lines.push(``);
  }

  lines.push(`NexusApp::create('my-app')`);
  if (s.otel) {
    lines.push(`    ->withObservability($observability)`);
  }
  lines.push(`    ->actor('hello', Props::fromBehavior(`);
  lines.push(`        Behavior::receive(static function ($ctx, $msg): Behavior {`);
  lines.push(`            $ctx->log()->info('received', ['type' => $msg::class]);`);
  lines.push(`            return Behavior::same();`);
  lines.push(`        })`);
  lines.push(`    ))`);

  for (const block of onStartBlocks) {
    lines.push(block);
  }

  lines.push(`    ->run(new ${runtimeClass}());`);

  return lines.join('\n');
}

function buildWorkerPoolBootstrap(s: Selections): string {
  const lines: string[] = [];

  lines.push(`<?php`);
  lines.push(``);
  lines.push(`declare(strict_types=1);`);
  lines.push(``);
  lines.push(`require __DIR__ . '/vendor/autoload.php';`);
  lines.push(``);
  lines.push(`use Monadial\\Nexus\\Core\\Actor\\Behavior;`);
  lines.push(`use Monadial\\Nexus\\Core\\Actor\\Props;`);
  lines.push(`use Monadial\\Nexus\\WorkerPool\\Swoole\\WorkerPoolApp;`);
  lines.push(`use Monadial\\Nexus\\WorkerPool\\WorkerNode;`);
  lines.push(`use Monadial\\Nexus\\WorkerPool\\WorkerPoolConfig;`);
  lines.push(``);
  lines.push(`final class MyApp extends WorkerPoolApp`);
  lines.push(`{`);
  lines.push(`    protected function configure(WorkerNode $node): void`);
  lines.push(`    {`);
  lines.push(`        $node->spawn(Props::fromBehavior(`);
  lines.push(`            Behavior::receive(static function ($ctx, $msg): Behavior {`);
  lines.push(`                $ctx->log()->info('received', ['type' => $msg::class]);`);
  lines.push(`                return Behavior::same();`);
  lines.push(`            })`);
  lines.push(`        ), 'hello');`);
  lines.push(`    }`);
  lines.push(`}`);
  lines.push(``);
  lines.push(`MyApp::run(WorkerPoolConfig::withThreads(8));`);

  return lines.join('\n');
}

// ── docker-compose.yml ───────────────────────────────────────────────────────

function buildCompose(s: Selections): string {
  const needsDb =
    s.persistence === 'es-dbal' ||
    s.persistence === 'es-doctrine' ||
    s.persistence === 'ds-dbal' ||
    s.persistence === 'ds-doctrine';

  // worker-pool requires ZTS PHP + Swoole --enable-swoole-thread; the -zts tag ships this.
  // swoole (coroutine-only) works on the non-ZTS alpine image.
  const appImage =
    s.runtime === 'worker-pool'
      ? 'phpswoole/swoole:6.2.1-php8.5-zts     # ZTS required for Swoole\\Thread\\Pool'
      : s.runtime === 'swoole'
      ? 'phpswoole/swoole:6.2.1-php8.5-alpine  # ≥6.2.1 required; verify tag exists on Docker Hub'
      : 'php:8.5-cli';

  let yaml = `services:\n`;
  yaml += `  app:\n`;
  yaml += `    image: ${appImage}\n`;
  yaml += `    working_dir: /app\n`;
  yaml += `    volumes:\n`;
  yaml += `      - .:/app\n`;
  yaml += `    command: ["php", "bootstrap.php"]\n`;

  if (needsDb) {
    yaml += `    depends_on:\n`;
    yaml += `      db:\n`;
    yaml += `        condition: service_healthy\n`;
    yaml += `    environment:\n`;
    yaml += `      DATABASE_URL: "postgres://nexus:nexus@db:5432/nexus"\n`;
    yaml += `  db:\n`;
    yaml += `    image: postgres:16-alpine\n`;
    yaml += `    environment:\n`;
    yaml += `      POSTGRES_USER: nexus\n`;
    yaml += `      POSTGRES_PASSWORD: nexus\n`;
    yaml += `      POSTGRES_DB: nexus\n`;
    yaml += `    healthcheck:\n`;
    yaml += `      test: ["CMD-SHELL", "pg_isready -U nexus"]\n`;
    yaml += `      interval: 5s\n`;
    yaml += `    volumes:\n`;
    yaml += `      - pgdata:/var/lib/postgresql/data\n`;
    yaml += `\n`;
    yaml += `volumes:\n`;
    yaml += `  pgdata:\n`;
  }

  return yaml;
}

// ── README.md ────────────────────────────────────────────────────────────────

const RUNTIME_LABELS: Record<Runtime, string> = {
  'fiber': 'PHP Fibers (single process, cooperative scheduling)',
  'swoole': 'Swoole coroutines (async I/O)',
  'worker-pool': 'Swoole worker pool (multi-thread, ZTS PHP + Swoole ≥ 6.2.1 required)',
};

const PERSISTENCE_LABELS: Record<Persistence, string> = {
  'none': '',
  'es-dbal': 'Event sourcing via Doctrine DBAL',
  'es-doctrine': 'Event sourcing via Doctrine ORM',
  'ds-dbal': 'Durable state via Doctrine DBAL',
  'ds-doctrine': 'Durable state via Doctrine ORM',
};

function buildReadme(s: Selections): string {
  const { prod, dev } = getPackages(s);
  const composerLine = `composer require ${prod.join(' ')}`;
  const devLine = `composer require --dev ${dev.join(' ')}`;

  const integrationNotes: string[] = [];
  if (s.http) integrationNotes.push('- HTTP server listens on **:8080**. Define routes in `routes.php`.');
  if (s.websockets) integrationNotes.push('- WebSocket support requires `nexus-actors/http-ws` — `WsApplication` and WebSocket handler live in that package.');
  if (s.otel) integrationNotes.push('- OpenTelemetry tracing: `nexus-actors/observability-otel` is not yet published to Packagist — track availability at [GitHub](https://github.com/nexus-actors/nexus).');
  if (s.runtime === 'worker-pool') integrationNotes.push('- Worker pool requires **ZTS PHP 8.5+** and Swoole compiled with `--enable-swoole-thread` (Swoole ≥ 6.2.1).');

  const persistenceNote = s.persistence !== 'none'
    ? `- Persistence: **${PERSISTENCE_LABELS[s.persistence]}**. Set \`DATABASE_URL\` in your environment before running.`
    : '';

  const noteLines = [...integrationNotes, persistenceNote].filter(Boolean).join('\n');

  return `# My Nexus app

This project was bootstrapped at https://nexusactors.com/bootstrap.

## 1. Install dependencies

\`\`\`bash
${composerLine}
${devLine}
\`\`\`

## 2. Bring up services

\`\`\`bash
docker compose up -d
\`\`\`

## 3. Run the actor system

\`\`\`bash
docker compose exec app php bootstrap.php
\`\`\`

## Notes

- Runtime: **${RUNTIME_LABELS[s.runtime]}**
${noteLines}
- Docs: ${(import.meta.env.PUBLIC_DOCS_URL || 'https://docs.nexusactors.com/docs').replace(/\/+$/, '')}/getting-started/quick-start
- API reference: ${import.meta.env.PUBLIC_API_URL || 'https://api.nexusactors.com'}
`;
}

// ── composer create-project command ─────────────────────────────────────────

/**
 * Returns the two-form create-project output:
 *   1. Interactive (just run the command, answer prompts)
 *   2. Non-interactive with env-var overrides derived from the wizard selections
 */
export function generateCreateCommand(s: Selections): string {
  const envVars: string[] = [];

  if (s.runtime !== 'fiber') {
    envVars.push(`NEXUS_RUNTIME=${s.runtime}`);
  }

  if (s.http || s.websockets) {
    envVars.push('NEXUS_HTTP=1');
  }

  if (s.doctrine) {
    envVars.push('NEXUS_DOCTRINE=1');
  }

  // Map wizard persistence keys to installer keys (wizard uses ds-*, installer uses durable-*)
  const persistenceMap: Record<Persistence, string> = {
    'none': 'none',
    'es-dbal': 'es-dbal',
    'es-doctrine': 'es-doctrine',
    'ds-dbal': 'durable-dbal',
    'ds-doctrine': 'durable-doctrine',
  };

  if (s.persistence !== 'none') {
    envVars.push(`NEXUS_PERSISTENCE=${persistenceMap[s.persistence]}`);
  }

  // OTel package not yet published — do not emit NEXUS_OTEL=1 until skeleton supports it

  const interactive = [
    'composer create-project nexus-actors/skeleton my-app',
    'cd my-app',
    '# The installer will ask about runtime, HTTP and persistence.',
    '# Answer with the same choices you made in the wizard above.',
  ].join('\n');

  if (envVars.length === 0) {
    // All defaults — non-interactive form is trivial
    return interactive;
  }

  const envPrefix = envVars.join(' ');
  const nonInteractive = [
    `${envPrefix} \\`,
    '    composer create-project nexus-actors/skeleton my-app -- --no-interaction',
    'cd my-app',
  ].join('\n');

  return `# Option A — interactive (recommended)\n${interactive}\n\n# Option B — skip the prompts\n${nonInteractive}`;
}

// ── Main export ──────────────────────────────────────────────────────────────

export function generate(s: Selections): Artifacts {
  return {
    composer: buildComposer(s),
    bootstrap: buildBootstrap(s),
    compose: buildCompose(s),
    readme: buildReadme(s),
  };
}

export const DEFAULT_SELECTIONS: Selections = {
  runtime: 'fiber',
  http: false,
  websockets: false,
  doctrine: false,
  otel: false,
  cluster: false,
  messenger: false,
  persistence: 'none',
};
