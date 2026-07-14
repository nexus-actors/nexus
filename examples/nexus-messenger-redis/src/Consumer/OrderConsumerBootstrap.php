<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\MessengerRedis\Consumer;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\MessengerRedis\Actor\OrderProcessor;
use Monadial\Nexus\Example\MessengerRedis\Message\OrderAccepted;
use Monadial\Nexus\Example\MessengerRedis\Message\OrderPlaced;
use Monadial\Nexus\Example\MessengerRedis\Serialization\SerializerFactory;
use Monadial\Nexus\Messenger\Console\Swoole\ThreadedConsumerBootstrap;
use Monadial\Nexus\Messenger\Routing\MapMessageRouter;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Override;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * ThreadedConsumerBootstrap for the Swoole thread-pool consumer.
 *
 * The pool instantiates this class FRESH inside every worker thread — no
 * cross-thread object sharing. Both methods run per-thread inside the configure
 * closure:
 *
 *   - setup()    spawns the OrderProcessor on that thread's ActorSystem and
 *                returns the router that targets it.
 *   - receiver() opens an INDEPENDENT Redis connection per thread, so N threads
 *                become N competing consumers on the same consumer group.
 *
 * Config is read from environment variables (each thread reads them itself);
 * this keeps the class constructor argument-free, as the pool requires — it is
 * invoked as `new OrderConsumerBootstrap()`.
 */
final class OrderConsumerBootstrap implements ThreadedConsumerBootstrap
{
    #[Override]
    public function setup(ActorSystem $system): MessageRouter
    {
        $processorRef = $system->spawn(
            Props::fromFactory(static fn(): OrderProcessor => new OrderProcessor()),
            'order-processor',
        );

        return new MapMessageRouter([OrderPlaced::class => $processorRef]);
    }

    #[Override]
    public function receiver(): ReceiverInterface
    {
        $dsn = (string) ($_SERVER['REDIS_DSN'] ?? 'redis://redis:6379');
        $stream = (string) ($_SERVER['REDIS_STREAM'] ?? 'orders');
        $group = (string) ($_SERVER['CONSUMER_GROUP'] ?? 'nexus-workers');

        $typeRegistry = new TypeRegistry();
        $typeRegistry->registerFromAttribute(OrderPlaced::class);
        $typeRegistry->registerFromAttribute(OrderAccepted::class);

        $serializer = new NexusMessengerSerializer(
            SerializerFactory::fromEnvironment($typeRegistry, [OrderAccepted::class, OrderPlaced::class]),
            $typeRegistry,
        );

        $connection = Connection::fromDsn($dsn . '/' . $stream, ['group' => $group]);

        return new RedisTransport($connection, $serializer);
    }
}
