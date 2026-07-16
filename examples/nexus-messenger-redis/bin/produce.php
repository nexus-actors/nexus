<?php

/**
 * nexus-messenger-redis — producer
 *
 * Publishes N OrderPlaced messages to a Redis Stream via the Symfony Redis
 * transport and the NexusMessengerSerializer, then exits.
 *
 * Usage (inside the example container):
 *   php bin/produce.php [count=10]
 *
 * Environment variables:
 *   REDIS_DSN      redis://redis:6379  (default)
 *   REDIS_STREAM   orders              (default)
 *   CONSUMER_GROUP nexus-workers       (default; the stream group is created
 *                                       automatically by the transport)
 */

declare(strict_types=1);

use Monadial\Nexus\Example\MessengerRedis\Message\OrderPlaced;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Producer\MessengerActorRef;
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

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
// 1. Serializer — explicit allow-list prevents PHP Object Injection (CWE-502)
// ---------------------------------------------------------------------------
$typeRegistry = new TypeRegistry();
$typeRegistry->registerFromAttribute(OrderPlaced::class);

$serializer = new NexusMessengerSerializer(
    new PhpNativeSerializer(allowedClasses: [OrderPlaced::class]),
    $typeRegistry,
);

// ---------------------------------------------------------------------------
// 2. Redis transport
// ---------------------------------------------------------------------------
$dsn = $_SERVER['REDIS_DSN'] ?? 'redis://redis:6379';
$stream = $_SERVER['REDIS_STREAM'] ?? 'orders';
$group = $_SERVER['CONSUMER_GROUP'] ?? 'nexus-workers';

/** @var SenderInterface $transport — the Redis bridge classes are installed only with the standalone example */
$transport = new RedisTransport(
    Connection::fromDsn($dsn . '/' . $stream, ['group' => $group]),
    $serializer,
);

// ---------------------------------------------------------------------------
// 3. Producer actor-ref — fire-and-forget via tell()
// ---------------------------------------------------------------------------
/** @var MessengerActorRef<OrderPlaced> $producer — producer()'s template appears only in its return type */
$producer = MessengerBridge::producer($transport, 'orders-out');

// ---------------------------------------------------------------------------
// 4. Publish messages
// ---------------------------------------------------------------------------
$count = (int) ($argv[1] ?? 10);

for ($i = 1; $i <= $count; $i++) {
    $producer->tell(new OrderPlaced(
        orderId: 'order-' . $i,
        customerId: 'customer-' . random_int(1, 5),
        amountCents: random_int(100, 9999),
    ));
    echo "Published order-{$i}\n";
}

echo "Done — {$count} messages published to stream \"{$stream}\" (group \"{$group}\") on {$dsn}\n";
