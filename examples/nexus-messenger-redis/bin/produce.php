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
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Serialization\PhpNativeSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;

require __DIR__ . '/../vendor/autoload.php';

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
$dsn = (string) ($_SERVER['REDIS_DSN'] ?? 'redis://redis:6379');
$stream = (string) ($_SERVER['REDIS_STREAM'] ?? 'orders');
$group = (string) ($_SERVER['CONSUMER_GROUP'] ?? 'nexus-workers');

$connection = Connection::fromDsn($dsn . '/' . $stream, ['group' => $group]);
$transport = new RedisTransport($connection, $serializer);

// ---------------------------------------------------------------------------
// 3. Producer actor-ref — fire-and-forget via tell()
// ---------------------------------------------------------------------------
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

echo "Done — {$count} messages published to stream \"{$stream}\" on {$dsn}\n";
