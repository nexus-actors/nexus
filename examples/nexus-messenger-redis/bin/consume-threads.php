<?php

/**
 * nexus-messenger-redis — threaded consumer (Swoole thread pool)
 *
 * Runs the nexus:messenger:consume-threads command: a Swoole thread pool where
 * each thread owns an independent ActorSystem + SwooleRuntime, a fresh Redis
 * connection, and N competing ReceiverActors. The broker load-balances across
 * threads because each thread holds its own consumer-group connection.
 *
 * Requires ext-swoole >= 6.2.1 built with --enable-swoole-thread (ZTS PHP).
 * The plain example image (Fiber-only) cannot run this — build/use a Swoole ZTS
 * image (see the monorepo docker/Dockerfile php-swoole target).
 *
 * Usage (inside a Swoole ZTS container):
 *   php bin/consume-threads.php --threads=4 --receivers=2 --limit=1000
 *
 * All limit options are PER-THREAD (each thread has its own LifecycleWatchdog).
 * No limits → the pool runs until SIGTERM. Options:
 *   --threads|-t    worker threads              (default 2)
 *   --receivers|-r  competing receivers/thread  (default 1)
 *   --limit         stop each thread after N messages
 *   --memory-limit  stop each thread at e.g. 128M / 1G
 *   --time-limit    stop each thread after N seconds
 *   --poll-interval receiver poll interval ms   (default 100)
 *   --dead-letters  route unroutable messages to dead letters
 *
 * Config that varies per thread (Redis DSN, stream, group, serializer) is read
 * from environment variables by OrderConsumerBootstrap inside each thread —
 * only the bootstrap CLASS-STRING crosses the thread boundary, never a live
 * object.
 *
 * Environment variables:
 *   REDIS_DSN      redis://redis:6379  (default)
 *   REDIS_STREAM   orders              (default)
 *   CONSUMER_GROUP nexus-workers       (default)
 *   SERIALIZER     php-native          (default; also: json, msgpack)
 */

declare(strict_types=1);

use Monadial\Nexus\Example\MessengerRedis\Consumer\OrderConsumerBootstrap;
use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumeCommand;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Application;

require __DIR__ . '/../vendor/autoload.php';

$logger = new Logger('nexus-consumer-threads');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

$app = new Application('nexus-messenger-redis-threads', '1.0.0');

// The command only receives the bootstrap CLASS-STRING. Each worker thread does
// `new OrderConsumerBootstrap()` itself, so no live object crosses the boundary.
$app->addCommand(new ThreadedConsumeCommand(OrderConsumerBootstrap::class, $logger));

$app->run();
