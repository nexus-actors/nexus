<?php

/**
 * nexus-messenger-redis — worker
 *
 * Nexus-owned queue worker with:
 *   - 3 competing ReceiverActors polling the same Redis Stream consumer group
 *   - LifecycleWatchdog that recycles the process after 50 messages (demo)
 *   - NexusMessengerSerializer with an explicit unserialize allow-list
 *   - PSR-14 StdoutDispatcher printing MessageConsumed / WorkerRecyclingTriggered
 *   - PSR-3 logger forwarding to stdout via Monolog
 *
 * Usage (inside the example container):
 *   php bin/worker.php
 *
 * Environment variables:
 *   REDIS_DSN         redis://redis:6379  (default)
 *   REDIS_STREAM      orders              (default)
 *   CONSUMER_GROUP    nexus-workers       (default)
 *   RECEIVER_COUNT    3                   (default; competing consumers)
 *   MESSAGE_LIMIT     50                  (default; watchdog recycles after N messages)
 *
 * Competing consumers:
 *   Each ReceiverActor is an independent Fiber that reads from the same
 *   Redis consumer group. The broker hands each pending message to exactly
 *   one consumer — classic competing-consumer / work-queue semantics.
 *   At-least-once delivery is guaranteed because ack happens only AFTER
 *   the actor mailbox accepts the envelope.
 *
 * Worker recycling:
 *   LifecycleWatchdog monitors messages processed across all receivers.
 *   When MESSAGE_LIMIT is reached it fires a WorkerRecyclingTriggered PSR-14
 *   event, then calls ActorSystem::shutdown() for a graceful drain.
 *   Your process manager (systemd, Kubernetes, supervisord) restarts the
 *   worker — giving a fresh PHP process with clean memory.
 */

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\MessengerRedis\Actor\OrderProcessor;
use Monadial\Nexus\Example\MessengerRedis\Event\StdoutDispatcher;
use Monadial\Nexus\Example\MessengerRedis\Message\OrderPlaced;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\Route;
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

// Standalone example install, or monorepo checkout — pick whichever exists.
$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
}

require $autoload;

// The Redis bridge ships only with the standalone example install; fail fast
// with a clear error when running from a checkout without it.
if (!class_exists('Symfony\Component\Messenger\Bridge\Redis\Transport\Connection')) {
    fwrite(STDERR, "symfony/redis-messenger is not installed — run `composer install` in examples/nexus-messenger-redis.\n");
    exit(1);
}

if (!class_exists('Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport')) {
    fwrite(STDERR, "symfony/redis-messenger is not installed — run `composer install` in examples/nexus-messenger-redis.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. PSR-3 logger — Monolog to stdout
// ---------------------------------------------------------------------------
$logger = new Logger('nexus-worker');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

// ---------------------------------------------------------------------------
// 2. PSR-14 dispatcher — prints bridge events to stdout
// ---------------------------------------------------------------------------
$events = new StdoutDispatcher();

// ---------------------------------------------------------------------------
// 3. Serializer — explicit allow-list prevents PHP Object Injection (CWE-502)
// ---------------------------------------------------------------------------
$typeRegistry = new TypeRegistry();
$typeRegistry->registerFromAttribute(OrderPlaced::class);

$serializer = new NexusMessengerSerializer(
    new PhpNativeSerializer(allowedClasses: [OrderPlaced::class]),
    $typeRegistry,
);

// ---------------------------------------------------------------------------
// 4. Redis transport
// ---------------------------------------------------------------------------
$dsn = $_SERVER['REDIS_DSN'] ?? 'redis://redis:6379';
$stream = $_SERVER['REDIS_STREAM'] ?? 'orders';
$group = $_SERVER['CONSUMER_GROUP'] ?? 'nexus-workers';

/** @var ReceiverInterface $transport — the Redis bridge classes are installed only with the standalone example */
$transport = new RedisTransport(
    Connection::fromDsn($dsn . '/' . $stream, ['group' => $group]),
    $serializer,
);

// ---------------------------------------------------------------------------
// 5. Actor system + Fiber runtime
// ---------------------------------------------------------------------------
$runtime = new FiberRuntime();
$system = ActorSystem::create('messenger-worker', $runtime, logger: $logger, eventDispatcher: $events);

// ---------------------------------------------------------------------------
// 6. OrderProcessor actor — receives routed OrderPlaced messages
// ---------------------------------------------------------------------------
$processorRef = $system->spawn(Props::fromFactory(fn () => new OrderProcessor()), 'order-processor');

// ---------------------------------------------------------------------------
// 7. 3 competing ReceiverActors — each polls the same Redis consumer group
// ---------------------------------------------------------------------------
$receiverCount = (int) ($_SERVER['RECEIVER_COUNT'] ?? 3);
$messageLimit = (int) ($_SERVER['MESSAGE_LIMIT'] ?? 50);

$router = new MapMessageRouter(Route::to(OrderPlaced::class, $processorRef));
$config = ReceiverActorConfig::default()->withPollInterval(Duration::millis(100));

MessengerBridge::spawnReceivers(
    $system,
    $receiverCount,
    'receiver',
    $transport,
    $router,
    $config,
    null,    // deadLetters — let unroutable messages surface in logs
    null,    // processedListener
    $events,
    null,    // observability — use NoopObservability default
);

// ---------------------------------------------------------------------------
// 8. LifecycleWatchdog — recycles this process after MESSAGE_LIMIT messages
// ---------------------------------------------------------------------------
$system->spawn(
    MessengerBridge::watchdogProps(
        $system,
        LifecycleThresholds::none()->withMessageLimit($messageLimit),
    ),
    'watchdog',
);

// ---------------------------------------------------------------------------
// 9. Run — blocks until the watchdog triggers graceful shutdown
// ---------------------------------------------------------------------------
$logger->info('Worker starting', [
    'consumer_group' => $group,
    'message_limit' => $messageLimit,
    'receiver_count' => $receiverCount,
    'redis_dsn' => $dsn,
    'stream' => $stream,
]);

$system->run();

$logger->info('Worker stopped — process manager should restart this worker.');
