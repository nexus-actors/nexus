<?php

/**
 * World benchmark hot-path component breakdown.
 *
 * Isolates each critical-path section and measures its per-operation cost so
 * bottlenecks can be identified, eliminated (where possible), and documented.
 *
 * Usage — without JIT (baseline):
 *   docker compose exec php-swoole php tests/Performance/hotpath_breakdown.php
 *
 * Usage — with JIT (recommended for production profiling):
 *   docker compose exec php-swoole php \
 *     -d opcache.enable_cli=1 -d opcache.jit=tracing \
 *     -d opcache.jit_buffer_size=64M \
 *     tests/Performance/hotpath_breakdown.php
 *
 * How to read the output:
 *   Each row shows µs/op (microseconds per operation) and the theoretical
 *   single-section throughput ceiling. The summary shows what fraction of
 *   the per-message budget each section consumes.
 */

declare(strict_types=1);

// ── Autoloader ────────────────────────────────────────────────────────────────

$autoloader = null;

foreach ([__DIR__ . '/../../vendor/autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $candidate) {
    if (file_exists($candidate)) {
        $autoloader = $candidate;

        break;
    }
}

if ($autoloader === null) {
    fwrite(STDERR, "ERROR: Cannot locate vendor/autoload.php\n");
    exit(1);
}

require_once $autoloader;
require_once __DIR__ . '/BenchmarkOrder.php';

use Monadial\Nexus\Core\Actor\ActorPath;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Mailbox\Envelope;
use Monadial\Nexus\Core\Message\SystemMessage;
use Swoole\Coroutine\Channel;

use function Swoole\Coroutine\run;

// ── Configuration ─────────────────────────────────────────────────────────────

const WARMUP_ITERS = 5_000;
const BENCH_ITERS  = 300_000;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Run $fn for BENCH_ITERS iterations and return µs per operation.
 * $fn is warmed up first so JIT traces are compiled before measurement.
 */
function run_bench(callable $fn, int $iters = BENCH_ITERS): float
{
    for ($i = 0; $i < WARMUP_ITERS; $i++) {
        $fn();
    }

    gc_collect_cycles();

    $start = hrtime(true);

    for ($i = 0; $i < $iters; $i++) {
        $fn();
    }

    return (hrtime(true) - $start) / $iters / 1000.0;
}

function section(string $title): void
{
    fprintf(STDERR, "\n  ── %-46s──\n", $title . ' ');
}

function row(string $label, float $usPerOp, string $note = ''): float
{
    $mops = $usPerOp > 0.0
        ? 1.0 / $usPerOp
        : INF;
    fprintf(STDERR, "  %-54s %5.2f µs   %8.2f M/s%s\n", $label, $usPerOp, $mops, $note !== '' ? "   ($note)" : '');

    return $usPerOp;
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

$targetPath = ActorPath::fromString('/user/shard-0');

// Two-item basket (most common tier in the benchmark pool)
$orderBronze = new BenchmarkOrder(
    customerId: 1,
    items: [
        ['name' => 'Mug', 'price' => 9.99,  'qty' => 2],
        ['name' => 'Pen', 'price' => 1.49,  'qty' => 5],
    ],
    orderId: 'ORD-000001',
    tier: 'bronze',
);

$envelope   = Envelope::of($orderBronze, ActorPath::root(), $targetPath);
$serialized = serialize($envelope);

// ── Header ────────────────────────────────────────────────────────────────────

$jitMode = opcache_get_configuration()['directives']['opcache.jit'] ?? 'n/a';

fwrite(
    STDERR,
    "\n  ══════════════════════════════════════════════════════════\n",
);
fwrite(STDERR, "  World Benchmark — Hot-Path Component Breakdown\n");
fprintf(STDERR, "  PHP %s · Swoole %s · JIT: %s\n", PHP_VERSION, SWOOLE_VERSION, $jitMode);
fwrite(
    STDERR,
    "  ══════════════════════════════════════════════════════════\n",
);

// ═══ 1. ENVELOPE CREATION (producer thread) ═══════════════════════════════════

section('1. ENVELOPE CREATION  [producer thread]');

$usRoot = row(
    'ActorPath::root() [thread-local cache hit]',
    run_bench(static fn() => ActorPath::root()),
);

$usRandBytes = row(
    'random_bytes(16) + bin2hex()  [Envelope IDs]',
    run_bench(static fn() => bin2hex(random_bytes(16))),
    'syscall per tell()',
);

$usEnvAlloc = row(
    'Envelope::of()  [full: rand + alloc + 3 field assign]',
    run_bench(static fn() => Envelope::of($orderBronze, ActorPath::root(), $targetPath)),
);

// ═══ 2. THREAD\QUEUE SERIALIZATION (cross-thread IPC) ════════════════════════

section('2. THREAD\\QUEUE SERIALIZATION  [cross-thread IPC]');

$usSerialize = row(
    'serialize(Envelope + BenchmarkOrder)',
    run_bench(static fn() => serialize($envelope)),
    'Thread\\Queue::push() cost',
);

fprintf(STDERR, "  %-54s %d bytes\n", 'Serialized payload size:', strlen($serialized));

$usUnserialize = row(
    'unserialize($serialized)',
    run_bench(static fn() => unserialize($serialized)),
    'Thread\\Queue::pop() cost',
);

$usQueueTotal = $usSerialize + $usUnserialize;
row(
    'Thread\\Queue roundtrip total  (push + pop)',
    $usQueueTotal,
    'caps per-worker at ' . number_format(1_000_000 / $usQueueTotal / 1000, 0) . ' K/s',
);

// ═══ 3. SWOOLE CHANNEL / MAILBOX (within worker thread) ══════════════════════

section('3. SWOOLE CHANNEL  [SwooleMailbox, worker thread]');

$usChanPushPop = 0.0;
$usChanIsEmpty = 0.0;

run(static function () use ($envelope, &$usChanPushPop, &$usChanIsEmpty): void {
    $ch = new Channel(65536);

    // Pre-fill to mid-capacity so push is always non-full and pop is always
    // non-empty → both operations take the fast synchronous path (no yield).
    for ($i = 0; $i < 32768; $i++) {
        $ch->push($envelope);
    }

    $usChanPushPop = run_bench(static function () use ($ch, $envelope): void {
        $ch->push($envelope, 0.001); // non-full → synchronous
        $ch->pop(0.001);             // non-empty → synchronous
    });

    fprintf(
        STDERR,
        "  %-54s %5.2f µs   %8.2f M/s   (%s)\n",
        'Channel::push()+pop() [no waiter, buffered]',
        $usChanPushPop,
        1.0 / $usChanPushPop,
        'fast path, no coroutine switch',
    );

    // isEmpty() is called in SwooleMailbox::dequeue() before every pop()
    $usChanIsEmpty = run_bench(static fn() => $ch->isEmpty());
    fprintf(
        STDERR,
        "  %-54s %5.2f µs   %8.2f M/s\n",
        'Channel::isEmpty()  [used in dequeue()]',
        $usChanIsEmpty,
        1.0 / $usChanIsEmpty,
    );
});

// ═══ 4. ACTOR MESSAGE PROCESSING (BehaviorWithState hot path) ════════════════

section('4. ACTOR PROCESSING  [BehaviorWithState handler]');

// BehaviorWithState::next() itself (new self, 1 obj alloc — no Option wrappers)
$state         = ['count' => 0, 'revenue' => 0.0];
$usBwsNext = row(
    'BehaviorWithState::next($state)  [1 obj alloc]',
    run_bench(static fn() => BehaviorWithState::next([
        'count'   => $state['count'] + 1,
        'revenue' => $state['revenue'] + 19.97,
    ])),
);

// applyStatefulBehavior() access pattern: isStopped + hasNewState() + state()
$bwsResult = BehaviorWithState::next($state);
$usApply = row(
    'result->isStopped + hasNewState() + state()',
    run_bench(static fn() => $bwsResult->isStopped() || $bwsResult->hasNewState() && $bwsResult->state()),
    'ActorCell::applyStatefulBehavior()',
);

// Full handler closure (what the benchmark actor runs per message)
$fullState = ['count' => 0, 'revenue' => 0.0];
$usHandler = row(
    'Full handler: instanceof+loop+match+next()',
    run_bench(static function () use ($orderBronze, &$fullState): BehaviorWithState {
        if (!($orderBronze instanceof BenchmarkOrder) || $orderBronze->items === []) {
            return BehaviorWithState::same();
        }

        $subtotal = 0.0;

        foreach ($orderBronze->items as $item) {
            $subtotal += (float) $item['price'] * (int) $item['qty'];
        }

        $discount = match ($orderBronze->tier) {
            'gold'   => 0.20,
            'silver' => 0.10,
            default  => 0.0,
        };

        $result = BehaviorWithState::next([
            'count'   => $fullState['count'] + 1,
            'revenue' => $fullState['revenue'] + $subtotal * (1.0 - $discount),
        ]);

        $fullState = $result->state();

        return $result;
    }),
    'complete actor work per message',
);

// ═══ 5. ACTORCELL DISPATCH OVERHEAD (extra PHP call stack) ═══════════════════

section('5. ACTORCELL DISPATCH OVERHEAD  [call stack per msg]');

// Baseline: empty closure call
$usNoop = row(
    'Empty closure call  [call-stack baseline]',
    run_bench(static fn() => null),
);

// SystemMessage instanceof check (fast negative path for user messages)
$msg = $orderBronze;
$usMsgInstanceof = row(
    'instanceof SystemMessage check  [early guard]',
    run_bench(static fn() => $msg instanceof SystemMessage),
);

// ActorPath string cast (used in WorkerNode::start() listener)
$usPathStr = row(
    '(string) ActorPath  [__toString for localRefs lookup]',
    run_bench(static fn() => (string) $targetPath),
);

// ═══ SUMMARY ═════════════════════════════════════════════════════════════════

// Actual per-message budget based on 3.17M/s steady-state measurement
// (no JIT) and 3.49M/s with JIT tracing.
$actualMops        = $jitMode === 'disable'
    ? 3_170_000.0
    : 3_490_000.0;
$budgetUs          = 1_000_000.0 / $actualMops;

// Critical path: producer + IPC + channel + actor
// (all sections run concurrently on different threads, so we compare vs. the
//  bottleneck — the longest section — not the sum)
$producerUs   = $usEnvAlloc; // producer does envelope + push (serialize)
$pushUs       = $usSerialize;
$popUs        = $usUnserialize;
$channelUs    = $usChanPushPop;
$actorUs      = $usHandler + $usApply;
$dispatchUs   = $usMsgInstanceof + $usPathStr;

$bottleneckUs   = max($producerUs + $pushUs, $popUs + $channelUs + $actorUs + $dispatchUs);
$bottleneckName = $producerUs + $pushUs >= ($popUs + $channelUs + $actorUs + $dispatchUs)
    ? 'Producer: Envelope::of + Thread\\Queue serialize'
    : 'Worker: Thread\\Queue unserialize + actor dispatch';

fwrite(
    STDERR,
    "\n  ══ CRITICAL PATH ANALYSIS ══════════════════════════════════\n\n",
);

fprintf(STDERR, "  %-48s %5.2f µs\n", 'Per-message budget @ actual throughput:', $budgetUs);
fwrite(STDERR, "\n");

fwrite(STDERR, "  PRODUCER (serializes + pushes to Thread\\Queue):\n");
fprintf(STDERR, "  %-48s %5.2f µs  %4.0f%%\n", '  Envelope::of()', $usEnvAlloc, $usEnvAlloc / $budgetUs * 100);
fprintf(
    STDERR,
    "  %-48s %5.2f µs  %4.0f%%\n",
    '  Thread\\Queue::push (serialize)',
    $usSerialize,
    $usSerialize / $budgetUs * 100,
);
fprintf(STDERR, "  %-48s %5.2f µs\n", '  → Producer total', $producerUs + $pushUs);

fwrite(STDERR, "\n  WORKER (pop + dispatch + actor handler):\n");
fprintf(
    STDERR,
    "  %-48s %5.2f µs  %4.0f%%\n",
    '  Thread\\Queue::pop (unserialize)',
    $usUnserialize,
    $usUnserialize / $budgetUs * 100,
);
fprintf(
    STDERR,
    "  %-48s %5.2f µs  %4.0f%%\n",
    '  Channel push+pop (SwooleMailbox)',
    $channelUs,
    $channelUs / $budgetUs * 100,
);
fprintf(
    STDERR,
    "  %-48s %5.2f µs  %4.0f%%\n",
    '  ActorCell dispatch overhead',
    $dispatchUs,
    $dispatchUs / $budgetUs * 100,
);
fprintf(
    STDERR,
    "  %-48s %5.2f µs  %4.0f%%\n",
    '  BehaviorWithState handler + apply',
    $actorUs,
    $actorUs / $budgetUs * 100,
);
fprintf(STDERR, "  %-48s %5.2f µs\n", '  → Worker total', $popUs + $channelUs + $actorUs + $dispatchUs);

fwrite(STDERR, "\n  (Producer and worker run concurrently — bottleneck = max of the two)\n");
fprintf(STDERR, "  Bottleneck:  %s\n", $bottleneckName);
fprintf(
    STDERR,
    "  Bottleneck cost: %.2f µs/msg → %.2f M/s/worker → %.1f M/s × 16 workers\n",
    $bottleneckUs,
    1.0 / $bottleneckUs,
    16.0 / $bottleneckUs,
);

fwrite(STDERR, "\n  FIXABLE HOTSPOTS:\n");
fprintf(STDERR, "  • Thread\\Queue serialize/unserialize (%.2f µs) — PHP native serialization\n", $usQueueTotal);
fwrite(STDERR, "    Cannot be eliminated without changing Thread\\Queue to carry pre-serialized\n");
fwrite(STDERR, "    payloads or using a shared-memory ring buffer instead of Thread\\Queue.\n");
fprintf(STDERR, "  • random_bytes(16) per Envelope (%.2f µs) — one getrandom() syscall/msg\n", $usRandBytes);
fwrite(STDERR, "    Could use a thread-local PRNG (mt_rand) to eliminate the syscall,\n");
fwrite(STDERR, "    at the cost of non-CSPRNG IDs (acceptable for tracing-only IDs).\n");
fprintf(STDERR, "  • BehaviorWithState::next() alloc (%.2f µs) — 1 PHP object alloc/msg\n", $usBwsNext);
fwrite(STDERR, "    Could cache a static 'same' result and only allocate on real state change.\n");

fwrite(
    STDERR,
    "\n  ══════════════════════════════════════════════════════════\n\n",
);
