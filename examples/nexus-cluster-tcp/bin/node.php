<?php

/**
 * nexus-cluster-tcp — cluster node entrypoint
 *
 * Boots a single cluster node whose role is governed by the NODE_ROLE env var:
 *
 *   greeter — spawns a GreeterActor, exposes it in the cluster, and serves forever.
 *   client  — waits for cluster convergence, then on a repeating 3-second timer
 *              (a) fire-and-forgets a Greet via tell and
 *              (b) sends a Greet via ask and prints the Greeted reply.
 *
 * The cluster layer is wired by ClusterNode::boot(), which auto-selects
 * SwooleMeshTransport when ext-swoole is loaded with a SwooleRuntime.
 * All peer connections are handled by Swoole coroutines; no threads needed.
 *
 * Environment variables (all have sensible defaults for local dev):
 *
 *   CLUSTER_NAME      cluster topology name — must match across all nodes (default: demo)
 *   NODE_DC           datacenter tag in NodeAddress (default: dc1)
 *   NODE_APP          application tag in NodeAddress (default: greet-app)
 *   NODE_ID           unique node identifier (default: node-a)
 *   BIND_HOST         TCP bind interface (default: 0.0.0.0)
 *   BIND_PORT         TCP bind port (default: 7355)
 *   ADVERTISE_HOST    Hostname peers use to connect back (default: 127.0.0.1)
 *   ADVERTISE_PORT    Advertised TCP port (default: 7355)
 *   SEEDS             Comma-separated seed endpoints, e.g. "node-a:7355,node-b:7355"
 *                     Empty seeds require singleNode=true (handled automatically).
 *   NODE_ROLE         greeter | client (default: greeter)
 *   GREETER_NODE      For client role: "dc/app/node" of the greeter node
 *                     (default: dc1/greet-app/node-a — matches node-a's defaults)
 */

declare(strict_types=1);

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterNode;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\TlsConfig;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\ClusterTcp\Event\StdoutEventDispatcher;
use Monadial\Nexus\Example\ClusterTcp\Message\ClientTick;
use Monadial\Nexus\Example\ClusterTcp\Message\Greet;
use Monadial\Nexus\Example\ClusterTcp\Message\Greeted;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

// Standalone example install, or monorepo checkout — pick whichever exists.
$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
}

require $autoload;

// ---------------------------------------------------------------------------
// 1. Configuration from environment
// ---------------------------------------------------------------------------

$clusterName = $_SERVER['CLUSTER_NAME'] ?? 'demo';
$nodeDc = $_SERVER['NODE_DC'] ?? 'dc1';
$nodeApp = $_SERVER['NODE_APP'] ?? 'greet-app';
$nodeId = $_SERVER['NODE_ID'] ?? 'node-a';
$bindHost = $_SERVER['BIND_HOST'] ?? '0.0.0.0';
$bindPort = (int) ($_SERVER['BIND_PORT'] ?? 7355);
$advertiseHost = $_SERVER['ADVERTISE_HOST'] ?? '127.0.0.1';
$advertisePort = (int) ($_SERVER['ADVERTISE_PORT'] ?? 7355);
$seedsEnv = $_SERVER['SEEDS'] ?? '';
$nodeRole = $_SERVER['NODE_ROLE'] ?? 'greeter';
$greeterNodeEnv = $_SERVER['GREETER_NODE'] ?? 'dc1/greet-app/node-a';

// Secure-mode (opt-in). Default path stays plaintext + open for local dev.
//   CLUSTER_TLS=1      → enable Swoole SSL on the bind + outbound peer links.
//   CLUSTER_TLS_CERT   → server certificate file  (default: /certs/node.crt)
//   CLUSTER_TLS_KEY    → server private key file  (default: /certs/node.key)
//   CLUSTER_TLS_CA     → CA bundle for peer verification (default: /certs/ca.crt)
//   CLUSTER_SECRET     → shared HMAC handshake secret; rejects unauthenticated peers.
$tlsEnabled = ($_SERVER['CLUSTER_TLS'] ?? '') === '1';
$tlsCertFile = $_SERVER['CLUSTER_TLS_CERT'] ?? '/certs/node.crt';
$tlsKeyFile = $_SERVER['CLUSTER_TLS_KEY'] ?? '/certs/node.key';
$tlsCaFile = $_SERVER['CLUSTER_TLS_CA'] ?? '/certs/ca.crt';
$clusterSecret = $_SERVER['CLUSTER_SECRET'] ?? '';

// ---------------------------------------------------------------------------
// 2. PSR-3 logger — Monolog to stdout
// ---------------------------------------------------------------------------

$logger = new Logger($nodeId);
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

// ---------------------------------------------------------------------------
// 3. PSR-14 event dispatcher — prints cluster lifecycle events to stdout
// ---------------------------------------------------------------------------

$events = new StdoutEventDispatcher();

// ---------------------------------------------------------------------------
// 4. Parse seed endpoints
//    Empty SEEDS → singleNode mode (node starts standalone, peers join it).
//    Non-empty SEEDS → cluster mode (node dials seeds on boot).
// ---------------------------------------------------------------------------

$seeds = [];

if ($seedsEnv !== '') {
    foreach (explode(',', $seedsEnv) as $seed) {
        $seed = trim($seed);

        if ($seed !== '') {
            $seeds[] = NodeEndpoint::fromString($seed);
        }
    }
}

$singleNode = $seeds === [];

// ---------------------------------------------------------------------------
// 5. Cluster topology
//    Short failure-detection window: maxNoHeartbeat 4 s so `docker compose kill
//    node-a` is visible in node-b's logs within ~5 seconds (Suspect → Down).
// ---------------------------------------------------------------------------

$selfAddress = new NodeAddress($clusterName, $nodeDc, $nodeApp, $nodeId);
$bindEndpoint = NodeEndpoint::fromString("{$bindHost}:{$bindPort}");
$advertiseEndpoint = NodeEndpoint::fromString("{$advertiseHost}:{$advertisePort}");

$topology = ClusterTopology::create(
    clusterName: $clusterName,
    self: $selfAddress,
    bindEndpoint: $bindEndpoint,
    advertiseEndpoint: $advertiseEndpoint,
    seeds: $seeds,
    singleNode: $singleNode,
)
->withHeartbeatInterval(Duration::seconds(1))
->withGossipInterval(Duration::seconds(1))
->withFailureDetection(
    // Short give-up window so a hard `docker compose kill` is declared Down within
    // ~5 s (TCP EOF → immediate Suspect, then Down after maxNoHeartbeat elapses).
    maxNoHeartbeat: Duration::seconds(4),
    // Keep phi at the library defaults (8.0 / 500 ms). A lower phiThreshold is too
    // sensitive for a 1 s heartbeat: ordinary coroutine/GC jitter would cross the
    // threshold and produce false Suspect→Down flapping during healthy operation.
    minStdDev: Duration::millis(500),
    phiThreshold: 8.0,
);

// ---------------------------------------------------------------------------
// 5a. Secure mode (opt-in via env). Off by default so the plaintext local-dev
//     path is unchanged. Combine both for defence in depth:
//       - TLS gives transport encryption + per-node identity (Swoole SSL).
//       - authSecret proves cluster membership via an HMAC-signed handshake, so
//         a reachable-but-unauthorised peer cannot join merely by matching the
//         cluster name.
//     Generate a self-signed CA + node cert with the openssl one-liner in the
//     README, then run with CLUSTER_TLS=1 CLUSTER_SECRET=... on every node.
// ---------------------------------------------------------------------------
if ($tlsEnabled) {
    $topology = $topology->withTls(new TlsConfig(
        certFile: $tlsCertFile,
        keyFile: $tlsKeyFile,
        caFile: $tlsCaFile,
        verifyPeer: true,
    ));

    $logger->info('TLS enabled for cluster links', [
        'ca' => $tlsCaFile,
        'cert' => $tlsCertFile,
        'verify_peer' => true,
    ]);
}

if ($clusterSecret !== '') {
    $topology = $topology->withAuthSecret($clusterSecret);

    $logger->info('Handshake authentication enabled (shared HMAC secret)');
}

// ---------------------------------------------------------------------------
// 6. User message type registry — shared with ClusterNode so user message
//    types are reachable on both encode and decode paths.
// ---------------------------------------------------------------------------

$typeRegistry = new TypeRegistry();
$typeRegistry->registerFromAttribute(Greet::class);
$typeRegistry->registerFromAttribute(Greeted::class);

// ---------------------------------------------------------------------------
// 7. Actor system + Swoole runtime
//    Both nodes use the same system name so actor paths are predictable:
//    the greeter lives at /nexus-cluster-demo/greeter on any node.
// ---------------------------------------------------------------------------

$runtime = new SwooleRuntime();
$system = ActorSystem::create(
    'nexus-cluster-demo',
    $runtime,
    logger: $logger,
    eventDispatcher: $events,
);

// ---------------------------------------------------------------------------
// 8. Boot inside scheduleOnce — Swoole socket operations (TCP server bind,
//    outbound dial) require a coroutine context, which exists only after
//    SwooleRuntime enters Co\run() via $system->run().
// ---------------------------------------------------------------------------

$exitCode = 0;

$runtime->scheduleOnce(
    Duration::millis(10),
    static function () use (
        $system,
        $topology,
        $typeRegistry,
        $nodeRole,
        $nodeId,
        $clusterName,
        $greeterNodeEnv,
        $logger,
        &$exitCode,
    ): void {
        // Boot the cluster node: wires transport, membership actor, gossip/heartbeat.
        $node = ClusterNode::boot($system, $topology, $typeRegistry, logger: $logger);

        // -------------------------------------------------------------------
        // Graceful leave on SIGTERM / SIGINT.
        //
        // Swoole\Process::signal registers a coroutine-safe handler inside the
        // running reactor (we are already inside Co\run() via $system->run()).
        // On `docker compose stop` (SIGTERM) or Ctrl-C (SIGINT) we broadcast a
        // Leave frame to every peer via ClusterNode::shutdown(), then drain the
        // actor system. Peers observe PeerDisconnected + NodeDown immediately —
        // no phi wait — because the Leave arrives before the socket closes.
        // Without this the process would just die (TCP EOF → Suspect → Down
        // after maxNoHeartbeat), indistinguishable from a hard kill.
        // -------------------------------------------------------------------
        $gracefulLeave = static function (int $signal) use ($node, $system, $logger): void {
            $logger->info('Signal received — broadcasting Leave and draining', ['signal' => $signal]);
            $node->shutdown();
            $system->shutdown(Duration::seconds(5));
        };

        \Swoole\Process::signal(SIGTERM, $gracefulLeave);
        \Swoole\Process::signal(SIGINT, $gracefulLeave);

        $logger->info('Cluster node booted', [
            'advertise' => (string) $topology->advertiseEndpoint,
            'bind' => (string) $topology->bindEndpoint,
            'role' => $nodeRole,
            'seeds' => array_map(static fn($e): string => (string) $e, $topology->seeds),
            'self' => $topology->self->toPathPrefix(),
        ]);

        if ($nodeRole === 'greeter') {
            // ---------------------------------------------------------------
            // Greeter node (node-a): expose a single actor that replies to
            // both tell (no reply, just logs) and ask (returns Greeted).
            // ---------------------------------------------------------------
            $greeterBehavior = Behavior::receive(
                /**
                 * @param ActorContext<object> $ctx
                 * @return Behavior<object>
                 */
                static function (ActorContext $ctx, object $msg) use ($nodeId): Behavior {
                    if (!$msg instanceof Greet) {
                        return Behavior::unhandled();
                    }

                    $greeting = sprintf('Hello, %s! Greetings from %s.', $msg->name, $nodeId);
                    $sender = $ctx->sender();
                    $senderClass = $sender !== null
                        ? $sender::class
                        : 'null';
                    $ctx->log()->info('Greet received', ['name' => $msg->name, 'sender_class' => $senderClass]);

                    // Reply on ask path (sender is ClusterReplyRef); no-op on tell path.
                    if ($sender !== null) {
                        $sender->tell(new Greeted($greeting));
                        $ctx->log()->info('Greeted reply sent');
                    }

                    return Behavior::same();
                },
            );

            $greeterRef = $system->spawn(Props::fromBehavior($greeterBehavior), 'greeter');
            $node->expose($greeterRef);

            $logger->info('Greeter actor exposed', ['path' => (string) $greeterRef->path()]);
        } elseif ($nodeRole === 'client') {
            // ---------------------------------------------------------------
            // Client node (node-b): wait for cluster convergence, then on each
            // 3-second tick:
            //   (a) tell the greeter a fire-and-forget Greet
            //   (b) ask the greeter and print the Greeted reply
            // ---------------------------------------------------------------
            $greeterNodeParts = explode('/', $greeterNodeEnv, 3);

            if (count($greeterNodeParts) !== 3) {
                $logger->error('GREETER_NODE must be "dc/app/node"', ['value' => $greeterNodeEnv]);
                $exitCode = 1;
                $system->shutdown(Duration::seconds(5));

                return;
            }

            [$greeterDc, $greeterApp, $greeterNodeId] = $greeterNodeParts;
            $greeterAddress = new NodeAddress($clusterName, $greeterDc, $greeterApp, $greeterNodeId);

            // Greeter lives at /user/greeter on node-a (spawned under the user guardian).
            $greeterPath = ActorPath::fromString('/user/greeter');

            $clientBehavior = Behavior::setup(
                /**
                 * @param ActorContext<object> $ctx
                 * @return Behavior<object>
                 */
                static function (ActorContext $ctx) use (
                    $node,
                    $greeterAddress,
                    $greeterPath,
                    $logger,
                    $nodeId,
                ): Behavior {
                    // Poll every 3 s. Fire first tick after 2 s (allow TCP handshake to settle).
                    $ctx->scheduleRepeatedly(
                        Duration::seconds(2),
                        Duration::seconds(3),
                        new ClientTick(),
                    );

                    return Behavior::receive(
                        /**
                         * @param ActorContext<object> $ctx
                         * @return Behavior<object>
                         */
                        static function (
                            ActorContext $ctx,
                            object $msg,
                        ) use ($node, $greeterAddress, $greeterPath, $logger, $nodeId): Behavior {
                            if ($msg instanceof ClientTick) {
                                // Request the current cluster view asynchronously.
                                // The membership actor will deliver a ClusterView message
                                // to this actor on the next event-loop tick — no yield needed.
                                /** @var ActorRef<ClusterView> $self — this actor handles ClusterView (and ClientTick) */
                                $self = $ctx->self();
                                $node->queryViewAsync($self);

                                return Behavior::same();
                            }

                            if ($msg instanceof ClusterView) {
                                $upCount = count($msg->upNodes());

                                if ($upCount < 2) {
                                    $logger->info('Waiting for cluster convergence', ['up_nodes' => $upCount]);

                                    return Behavior::same();
                                }

                                // (a) Fire-and-forget tell — greeter logs it, no reply path.
                                $node->refFor($greeterAddress, $greeterPath)
                                    ->tell(new Greet('World'));

                                $logger->info('tell → Greet(name="World") sent to greeter');

                                // (b) Request-response ask — suspends coroutine until reply arrives.
                                try {
                                    /** @var Future<object> $future — the reply type of a remote ask is unknowable at the call site */
                                    $future = $node->refFor($greeterAddress, $greeterPath)
                                        ->ask(new Greet($nodeId), Duration::seconds(5));
                                    $reply = $future->await();

                                    if ($reply instanceof Greeted) {
                                        $logger->info('ask  ← Greeted received', ['message' => $reply->message]);
                                    }
                                } catch (\Throwable $e) {
                                    $logger->warning('ask timed out or failed', ['error' => $e->getMessage()]);
                                }

                                return Behavior::same();
                            }

                            return Behavior::unhandled();
                        },
                    );
                },
            );

            $system->spawn(Props::fromBehavior($clientBehavior), 'client');

            $logger->info('Client actor spawned — will greet node-a every 3 seconds');
        } else {
            $logger->error('Unknown NODE_ROLE — expected "greeter" or "client"', ['role' => $nodeRole]);
            $exitCode = 1;
            $system->shutdown(Duration::seconds(5));
        }
    },
);

// ---------------------------------------------------------------------------
// 9. Run — blocks inside Co\run() until the process is killed (SIGTERM/SIGKILL).
//    node-a: runs indefinitely serving the greeter.
//    node-b: runs indefinitely sending greetings every 3 seconds.
// ---------------------------------------------------------------------------

$logger->info('Starting actor system', [
    'node' => $selfAddress->toPathPrefix(),
    'role' => $nodeRole,
]);

$system->run();

$logger->info('Actor system stopped.');

exit($exitCode);
