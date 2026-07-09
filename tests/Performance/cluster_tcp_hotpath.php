<?php

/**
 * Cluster-TCP hot-path component breakdown.
 *
 * The loopback benchmark measured ~31.6 µs per 1 KB remote tell (sender +
 * receiver sharing one core) versus ~3.3 µs for a local short-circuit delivery.
 * This script prices each userland stage of the remote path in isolation so
 * the missing ~28 µs can be attributed to specific components instead of
 * guessed at. Anything not measurable here (socket syscalls, coroutine
 * scheduling, the mailbox hop) is the remainder.
 *
 * Usage:
 *   docker compose exec php-swoole php tests/Performance/cluster_tcp_hotpath.php
 */

declare(strict_types=1);

use Monadial\Nexus\Cluster\NodeAddress;
use Monadial\Nexus\Cluster\Tcp\ClusterTopology;
use Monadial\Nexus\Cluster\Tcp\Frame;
use Monadial\Nexus\Cluster\Tcp\FrameCodec;
use Monadial\Nexus\Cluster\Tcp\FrameType;
use Monadial\Nexus\Cluster\Tcp\Membership\ClusterView;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberRecord;
use Monadial\Nexus\Cluster\Tcp\Membership\MembershipService;
use Monadial\Nexus\Cluster\Tcp\Membership\MemberStatus;
use Monadial\Nexus\Cluster\Tcp\Membership\PhiAccrualDetector;
use Monadial\Nexus\Cluster\Tcp\Messaging\ClusterMessageCodec;
use Monadial\Nexus\Cluster\Tcp\NodeEndpoint;
use Monadial\Nexus\Cluster\Tcp\Payload\GossipPayload;
use Monadial\Nexus\Cluster\Tcp\Payload\Handshake;
use Monadial\Nexus\Cluster\Tcp\Payload\HandshakeAck;
use Monadial\Nexus\Cluster\Tcp\Payload\LeavePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayload;
use Monadial\Nexus\Cluster\Tcp\Payload\MessagePayloadCodec;
use Monadial\Nexus\Cluster\Tcp\Tests\Fixture\Ping;
use Monadial\Nexus\Core\Net\Host;
use Monadial\Nexus\Core\Net\Port;
use Monadial\Nexus\Observability\Metric\NoopMeter;
use Monadial\Nexus\Observability\Trace\NoopTracer;
use Monadial\Nexus\Observability\Trace\SpanKind;
use Monadial\Nexus\Serialization\Msgpack\MessagePackMessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;

require __DIR__ . '/../../vendor/autoload.php';

const WARMUP_ITERS = 5_000;
const BENCH_ITERS = 200_000;
const MEASURED_FULL_PATH_US = 31.6; // 1 KB remote tell, loopback benchmark
const MEASURED_LOCAL_US = 3.3;      // local short-circuit delivery

/**
 * @param callable(): void $op
 */
function bench(string $label, callable $op): float
{
    for ($i = 0; $i < WARMUP_ITERS; ++$i) {
        $op();
    }

    $start = hrtime(true);

    for ($i = 0; $i < BENCH_ITERS; ++$i) {
        $op();
    }

    $usPerOp = (hrtime(true) - $start) / 1_000.0 / BENCH_ITERS;

    printf("  %-38s %8.3f us/op  (%s ops/s ceiling)\n", $label, $usPerOp, number_format(1_000_000 / $usPerOp));

    return $usPerOp;
}

// ── Fixture: the exact stack ClusterNode::boot builds ─────────────────────────

$registry = new TypeRegistry();
$registry->registerFromAttribute(GossipPayload::class);
$registry->registerFromAttribute(Handshake::class);
$registry->registerFromAttribute(HandshakeAck::class);
$registry->registerFromAttribute(LeavePayload::class);
$registry->registerFromAttribute(MessagePayload::class);
$registry->registerFromAttribute(Ping::class);

$frameSerializer = new MessagePackMessageSerializer($registry);
$codec = new ClusterMessageCodec($frameSerializer, $registry);
$frameCodec = new FrameCodec();

$message = new Ping(str_repeat('x', 1_024));
$encoded = $codec->encode($message);
$payload = new MessagePayload(
    targetPath: '/user/throughput-sink',
    messageType: $encoded->type,
    body: $encoded->body,
    correlationId: null,
    replyPath: null,
    trace: [],
);
$envelopeBytes = $frameSerializer->serialize($payload);
$frame = new Frame(FrameType::Message, $envelopeBytes);
$wireBytes = $frameCodec->encode($frame);

$meter = new NoopMeter();
$tracer = new NoopTracer();

// Membership fixture: two-node view, peer already known and Up (steady state).
$selfAddr = new NodeAddress('bench', 'local', 'nexus', 'node-a');
$peerAddr = new NodeAddress('bench', 'local', 'nexus', 'node-b');
$endpoint = new NodeEndpoint(Host::of('127.0.0.1'), Port::of(7355));
$topology = ClusterTopology::create(
    clusterName: 'bench',
    self: $selfAddr,
    bindEndpoint: $endpoint,
    advertiseEndpoint: $endpoint,
    seeds: [],
    singleNode: true,
);
$service = new MembershipService($topology);
$now = new DateTimeImmutable();
$view = ClusterView::empty()
    ->withMember(new MemberRecord($selfAddr, $endpoint, 1, MemberStatus::Up, $now))
    ->withMember(new MemberRecord($peerAddr, $endpoint, 1, MemberStatus::Up, $now));
$detector = new PhiAccrualDetector();
$peerKey = $peerAddr->toPathPrefix();

printf(
    "cluster-tcp hot-path breakdown — %s iters, 1 KB payload, PHP %s, msgpack ext: %s\n\n",
    number_format(BENCH_ITERS),
    PHP_VERSION,
    extension_loaded('msgpack')
        ? 'yes'
        : 'NO (pure-PHP rybakit)',
);

echo "SEND SIDE (per remote tell)\n";
$encodeUs = bench('codec->encode (user msg serialize)', static function () use ($codec, $message): void {
    (void) $codec->encode($message);
});
$envSerUs = bench('envelope serialize (MessagePayload)', static function () use ($frameSerializer, $payload): void {
    (void) $frameSerializer->serialize($payload);
});
$frameEncUs = bench('frame encode (length prefix)', static function () use ($frameCodec, $frame): void {
    (void) $frameCodec->encode($frame);
});
$counterUs = bench('meter->counter(...)->add per call', static function () use ($meter): void {
    $meter
        ->counter('nexus.cluster.messages.sent', '{message}', 'Remote cluster tells sent')
        ->add(1, ['nexus.message.type' => 'test.ping']);
});
$spanUs = bench('tracer startSpan+end (noop)', static function () use ($tracer): void {
    $span = $tracer->startSpan('cluster.send', SpanKind::Producer, [
        'messaging.system' => 'nexus-tcp',
        'nexus.cluster.peer' => '/cluster/bench/local/nexus/node-b',
        'nexus.message.type' => 'test.ping',
    ]);
    $span->end();
});

echo "FAST ENVELOPE CODEC (MessagePayloadCodec, replaces Valinor for the envelope)\n";
$fastCodec = new MessagePayloadCodec();
$fastEnvelopeBytes = $fastCodec->pack($payload);
$fastPackUs = bench('payloadCodec->pack', static function () use ($fastCodec, $payload): void {
    (void) $fastCodec->pack($payload);
});
$fastUnpackUs = bench('payloadCodec->unpack', static function () use ($fastCodec, $fastEnvelopeBytes): void {
    (void) $fastCodec->unpack($fastEnvelopeBytes);
});

echo "\nRECEIVE SIDE (per inbound Message frame)\n";
$envDeserUs = bench(
    'envelope deserialize (MessagePayload)',
    static function () use ($frameSerializer, $envelopeBytes): void {
        (void) $frameSerializer->deserialize($envelopeBytes, 'cluster.message');
    },
);
$bodyDecUs = bench('body decode (user msg deserialize)', static function () use ($codec, $encoded): void {
    (void) $codec->decode($encoded->type, $encoded->body);
});
$nowUs = bench('clock->now (new DateTimeImmutable)', static function (): void {
    (void) new DateTimeImmutable();
});
$phiUs = bench('phi detector heartbeat', static function () use ($detector, $peerKey, $now): void {
    $detector->heartbeat($peerKey, $now);
});
$livenessUs = bench(
    'applyLiveness (full transition)',
    static function () use ($service, $view, $detector, $peerAddr, $now): void {
        (void) $service->applyLiveness($view, [], 1, $detector, $peerAddr, null, $now);
    },
);

// ── Summary ───────────────────────────────────────────────────────────────────

$sendSide = $encodeUs + $envSerUs + $frameEncUs + 2 * $counterUs + $spanUs;
// Receive side: envelope + body decode + FrameIngress metrics (2 counters) +
// the per-message liveness pipeline (message alloc→mailbox→now→transition).
$receiveSide = $envDeserUs + $bodyDecUs + 2 * $counterUs + $nowUs + $livenessUs;
$accounted = $sendSide + $receiveSide;
$serializationTotal = $encodeUs + $envSerUs + $envDeserUs + $bodyDecUs;
$livenessPipeline = $nowUs + $livenessUs;

printf("\nSUMMARY (vs %.1f us/msg measured full path, both sides on one core)\n", MEASURED_FULL_PATH_US);
printf(
    "  send-side userland          %6.2f us  (%4.1f%% of budget)\n",
    $sendSide,
    $sendSide / MEASURED_FULL_PATH_US * 100,
);
printf(
    "  receive-side userland       %6.2f us  (%4.1f%% of budget)\n",
    $receiveSide,
    $receiveSide / MEASURED_FULL_PATH_US * 100,
);
printf(
    "  -- serialization (2x2)      %6.2f us  (%4.1f%% of budget)\n",
    $serializationTotal,
    $serializationTotal / MEASURED_FULL_PATH_US * 100,
);
printf(
    "  -- liveness pipeline        %6.2f us  (%4.1f%% of budget)\n",
    $livenessPipeline,
    $livenessPipeline / MEASURED_FULL_PATH_US * 100,
);
printf(
    "  accounted userland total    %6.2f us  (%4.1f%% of budget)\n",
    $accounted,
    $accounted / MEASURED_FULL_PATH_US * 100,
);
printf(
    "  unaccounted remainder       %6.2f us  (sockets, coroutine scheduler, mailbox hop; local delivery alone is %.1f us)\n",
    MEASURED_FULL_PATH_US - $accounted,
    MEASURED_LOCAL_US,
);
