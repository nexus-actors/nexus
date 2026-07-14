<?php

/**
 * nexus-messenger-redis — asker (broker request/reply)
 *
 * Publishes ONE OrderPlaced as an ask and prints the correlated OrderAccepted
 * reply, then exits. This is the asker side of the ask/reply flow; the
 * responder side is the console consumer (`bin/console nexus:messenger:consume`),
 * which wires a MapReplySenderLocator so OrderProcessor's reply routes back over
 * the "replies" stream.
 *
 * Wiring:
 *   MessengerBridge::askSupport()  — owns the reply channel + pending-ask
 *                                    registry; spawns the nexus-ask-replies
 *                                    consumer actor lazily on the first ask.
 *   TransportReplyChannelFactory   — builds the reply channel from a DSN
 *                                    template; the logical channel name "replies"
 *                                    must match the console consumer's locator key.
 *   MessengerActorRef::ask()       — stamps X-Nexus-Correlation-Id + X-Nexus-Reply-To,
 *                                    sends the request, returns a Future.
 *   Future::await()                — suspends this fiber until the reply arrives
 *                                    (or the timeout fires).
 *
 * Run the responder first, then this asker:
 *   # terminal 1 — responder (keeps running, answers asks):
 *   docker compose run --rm -e SERIALIZER=json app php bin/console nexus:messenger:consume --receivers=1
 *
 *   # terminal 2 — asker (one request, prints the reply, exits):
 *   docker compose run --rm -e SERIALIZER=json app php bin/ask.php A-42
 *
 * IMPORTANT: run both sides with the SAME SERIALIZER value (the request and the
 * reply share one serializer). The ask/reply headers travel out-of-body, so any
 * of php-native | json | msgpack works as long as both sides agree.
 *
 * Environment variables:
 *   REDIS_DSN      redis://redis:6379  (default)
 *   REDIS_STREAM   orders              (default; request stream)
 *   REPLY_STREAM   replies             (default; reply stream — must match the consumer)
 *   CONSUMER_GROUP nexus-workers       (default)
 *   SERIALIZER     php-native          (default; also: json, msgpack)
 *   ASK_TIMEOUT    10                  (default; seconds to wait for the reply)
 */

declare(strict_types=1);

use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Example\MessengerRedis\Message\OrderAccepted;
use Monadial\Nexus\Example\MessengerRedis\Message\OrderPlaced;
use Monadial\Nexus\Example\MessengerRedis\Serialization\SerializerFactory;
use Monadial\Nexus\Messenger\Ask\ReplyQueueLifecycle;
use Monadial\Nexus\Messenger\Ask\TransportReplyChannelFactory;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Serialization\NexusMessengerSerializer;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use Monadial\Nexus\Serialization\TypeRegistry;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// 1. PSR-3 logger — Monolog to stdout
// ---------------------------------------------------------------------------
$logger = new Logger('nexus-asker');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// ---------------------------------------------------------------------------
// 2. Serializer + TypeRegistry — both request and reply types are registered so
//    the shared serializer can round-trip OrderPlaced (out) and OrderAccepted (back).
// ---------------------------------------------------------------------------
$typeRegistry = new TypeRegistry();
$typeRegistry->registerFromAttribute(OrderPlaced::class);
$typeRegistry->registerFromAttribute(OrderAccepted::class);

$nexusSerializer = SerializerFactory::fromEnvironment($typeRegistry, [OrderAccepted::class, OrderPlaced::class]);
$serializer = new NexusMessengerSerializer($nexusSerializer, $typeRegistry);

// ---------------------------------------------------------------------------
// 3. Request transport (the "orders" stream the consumer polls).
// ---------------------------------------------------------------------------
$dsn = (string) ($_SERVER['REDIS_DSN'] ?? 'redis://redis:6379');
$stream = (string) ($_SERVER['REDIS_STREAM'] ?? 'orders');
$replyStream = (string) ($_SERVER['REPLY_STREAM'] ?? 'replies');
$group = (string) ($_SERVER['CONSUMER_GROUP'] ?? 'nexus-workers');
$askTimeout = (int) ($_SERVER['ASK_TIMEOUT'] ?? 10);

$connection = Connection::fromDsn($dsn . '/' . $stream, ['group' => $group]);
$requestTransport = new RedisTransport($connection, $serializer);

// ---------------------------------------------------------------------------
// 4. Reply channel factory — builds the transport this asker consumes replies
//    from. The channel's logical name ("replies") is the key the responder's
//    MapReplySenderLocator maps back to its reply transport; the DSN here is
//    resolved locally, never from a wire value (SSRF hardening).
// ---------------------------------------------------------------------------
$replyChannelFactory = new TransportReplyChannelFactory(
    new RedisTransportFactory(),
    $serializer,
    $dsn . '/' . $replyStream . '?group=' . $group,
    'replies',
    ReplyQueueLifecycle::Persistent,
);

// ---------------------------------------------------------------------------
// 5. Actor system + Fiber runtime. The ask must run inside a fiber because
//    Future::await() suspends the current fiber until the reply arrives.
// ---------------------------------------------------------------------------
$runtime = new FiberRuntime();
$system = ActorSystem::create('messenger-asker', $runtime, logger: $logger);

$askSupport = MessengerBridge::askSupport($system, $replyChannelFactory);
$producer = MessengerBridge::producer($requestTransport, 'orders-out', askSupport: $askSupport);

$orderId = (string) ($argv[1] ?? 'A-42');

// ---------------------------------------------------------------------------
// 6. Asker actor — issues the ask inside its own fiber, awaits the reply,
//    prints it, then drains the system so the process exits.
// ---------------------------------------------------------------------------
$askerBehavior = Behavior::setup(
    static function (ActorContext $ctx) use (
        $producer,
        $orderId,
        $askTimeout,
        $system,
        $logger,
    ): Behavior {
        $request = new OrderPlaced(
            orderId: $orderId,
            customerId: 'customer-1',
            amountCents: 4_999,
        );

        $logger->info('ask → publishing request', ['order_id' => $orderId]);

        try {
            $reply = $producer->ask($request, Duration::seconds($askTimeout))->await();

            if ($reply instanceof OrderAccepted) {
                $logger->info('ask ← reply received', [
                    'order_id' => $reply->orderId,
                    'status' => $reply->status,
                ]);
            } else {
                $logger->warning('ask ← unexpected reply type', ['type' => $reply::class]);
            }
        } catch (\Throwable $e) {
            $logger->error('ask failed', ['error' => $e->getMessage()]);
        }

        $system->shutdown(Duration::seconds(5));

        return Behavior::stopped();
    },
);

$system->spawn(Props::fromBehavior($askerBehavior), 'asker');

// ---------------------------------------------------------------------------
// 7. Run — blocks until the asker awaits the reply and shuts the system down.
// ---------------------------------------------------------------------------
$system->run();

$logger->info('Asker stopped.');
