# Nexus Streams Design

## Summary

Implement an Akka Streams-inspired reactive streaming library for Nexus — the first full-featured, backpressured, typed streaming library for PHP. Streams compose via PHP 8.5's pipe operator (`|>`), materialize as actor graphs with pull-based demand signaling, and support arbitrary fan-in/fan-out topologies via a GraphDSL builder.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Execution model | Actor-per-stage | Consistent with Nexus architecture; leverages supervision, mailbox, clustering directly. Fusion optimization deferred to future phase. |
| Backpressure | Pull-based demand signaling | Most precise control, prevents unbounded buffering. Downstream signals demand upstream. |
| Composition syntax | PHP 8.5 pipe operator (`\|>`) | Stages are callables; `Flow::map(fn)` returns `fn(Source<A>): Source<B>`. Natural left-to-right reading. |
| Actor visibility | Hidden from user | Like Akka: materializer spawns actors internally. Users compose Source/Flow/Sink without touching actors. |
| Materialized values | Full Akka-style | Every stage can produce a mat value. Keep combinators (left, right, both, none) compose them. |
| Graph support | Full GraphDSL from start | Builder for arbitrary acyclic topologies with typed ports, fan-in/fan-out junctions, and partial graphs. |
| Package structure | PSR-style interface + implementation | `nexus-streams-api` (interfaces only) + `nexus-streams` (implementation). Other libraries could implement the same interfaces. |

## Package Structure

```
nexus-streams-api/          # Interface package (PSR-style)
├── src/
│   ├── Source.php           # Source<Out, +Mat> interface
│   ├── Flow.php             # Flow<In, Out, +Mat> interface
│   ├── Sink.php             # Sink<In, +Mat> interface
│   ├── Graph.php            # Graph<Shape, +Mat> interface
│   ├── RunnableStream.php   # RunnableStream<+Mat> — ready to materialize
│   ├── Shape/
│   │   ├── Shape.php            # Base shape marker
│   │   ├── SourceShape.php      # Has outlet only
│   │   ├── SinkShape.php        # Has inlet only
│   │   ├── FlowShape.php        # Has inlet + outlet
│   │   ├── ClosedShape.php      # Fully connected (runnable)
│   │   ├── FanOutShape.php      # 1 inlet, N outlets
│   │   └── FanInShape.php       # N inlets, 1 outlet
│   ├── Inlet.php            # Typed inlet port
│   ├── Outlet.php           # Typed outlet port
│   └── Materializer.php     # Materializer interface

nexus-streams/              # Implementation package
├── src/
│   ├── Dsl/
│   │   ├── Sources.php      # Static factories: from(), single(), repeat(), tick(), etc.
│   │   ├── Flows.php        # Static factories: map(), filter(), grouped(), scan(), etc.
│   │   ├── Sinks.php        # Static factories: foreach(), fold(), head(), seq(), etc.
│   │   └── GraphDsl.php     # Builder for complex topologies
│   ├── Stage/               # Internal actor implementations
│   │   ├── SourceStage.php
│   │   ├── FlowStage.php
│   │   ├── SinkStage.php
│   │   ├── FanOutStage.php
│   │   └── FanInStage.php
│   ├── Demand/              # Pull-based backpressure protocol
│   │   ├── Request.php      # "I can accept N more elements"
│   │   ├── OnNext.php       # "Here is the next element"
│   │   ├── OnComplete.php   # "No more elements"
│   │   └── OnError.php      # "Stream failed"
│   ├── Mat/                 # Materialized value combinators
│   │   ├── Keep.php         # Keep::left(), Keep::right(), Keep::both(), Keep::none()
│   │   └── MatValue.php     # Wrapper for materialized values
│   ├── ActorMaterializer.php   # Spawns actors for each stage
│   └── StreamSupervisor.php    # Top-level supervision for stream graphs
```

### Dependency Graph

```
nexus-core (no deps)
├── nexus-streams-api (Core only — interfaces)
│   └── nexus-streams (Core + streams-api — implementation)
```

Deptrac enforces: Core never depends on streams. Streams-api depends only on Core. Streams depends on Core + streams-api.

## Core Type System

### Blueprint Types

All types are blueprints (descriptions of what to run), not running things:

```php
// @template Out
// @template Mat
interface Source {
    // A source has an outlet that produces elements of type Out
    // When materialized, it produces a value of type Mat
}

// @template In
// @template Out
// @template Mat
interface Flow {
    // A flow has an inlet (In) and an outlet (Out)
    // Transforms elements from In to Out
}

// @template In
// @template Mat
interface Sink {
    // A sink has an inlet that consumes elements of type In
    // When materialized, it produces a value of type Mat
}

// @template Mat
interface RunnableStream {
    public function run(ActorSystem $system): Mat;
}
```

### Pipe Operator Composition

Stages as callables for `|>` compatibility:

```php
// Source is the starting point
$source = Sources::from([1, 2, 3, 4, 5]);  // Source<int, void>

// Flow factories return callables: fn(Source<In, Mat1>): Source<Out, Mat1>
$doubled = Flows::map(fn(int $x): int => $x * 2);
$filtered = Flows::filter(fn(int $x): bool => $x > 4);

// Sink factories return callables: fn(Source<In, Mat1>): RunnableStream<Mat2>
$printer = Sinks::foreach(fn(int $x) => print("$x\n"));

// Compose with |>
$stream = $source |> $doubled |> $filtered |> $printer;
// $stream is RunnableStream<void>

$stream->run($system);
```

### Materialized Value Composition

```php
// Sources and Sinks can have mat values
$source = Sources::tick(Duration::seconds(1), 'tick');  // Source<string, Cancellable>
$sink = Sinks::fold(0, fn(int $acc, string $x): int => $acc + 1);

// By default, rightmost mat value wins (Keep.right)
$stream = $source |> $sink;  // RunnableStream<int>

// To keep source's mat value or both:
$stream = $source |> Sinks::fold(0, fn($acc, $x) => $acc + 1)->keepLeft();
// RunnableStream<Cancellable>

$stream = $source |> Sinks::fold(0, fn($acc, $x) => $acc + 1)->keepBoth();
// RunnableStream<Pair<Cancellable, int>>
```

## Demand Protocol & Backpressure

### Protocol Messages

Internal messages between connected stage actors:

```php
readonly class Request {
    public function __construct(public int $demand) {}
}

readonly class OnNext {
    public function __construct(public mixed $element) {}
}

readonly class OnComplete {}

readonly class OnError {
    public function __construct(public \Throwable $cause) {}
}
```

### Message Flow

```
Upstream Actor                    Downstream Actor
     │                                  │
     │  ◄── Request(demand: int) ──────│  "I can accept N elements"
     │                                  │
     │  ── OnNext(element: T) ────────►│  "Here is element"
     │  ── OnNext(element: T) ────────►│  (repeats up to N times)
     │                                  │
     │  ── OnComplete() ─────────────►│  "No more elements"
     │  ── OnError(Throwable) ────────►│  "Stream failed"
```

### Demand Batching

High/low watermark strategy to reduce protocol overhead:

```php
private int $demand = 0;
private const int BUFFER_SIZE = 16;
private const int LOW_WATERMARK = 4;

// Re-request when demand drops below watermark
if ($this->demand <= self::LOW_WATERMARK) {
    $upstream->tell(new Request(self::BUFFER_SIZE));
    $this->demand += self::BUFFER_SIZE;
}
```

### Backpressure Propagation

1. Slow sink stops sending `Request` messages
2. Upstream flow has no demand → stops requesting from its upstream
3. Source has no demand → fiber suspends (no wasted CPU)
4. When sink catches up and drops below watermark → sends `Request`
5. Demand propagates upstream, source resumes

No data loss. No buffer overflow. Pipeline runs at slowest stage speed.

### Error Propagation

```
Exception in FlowActor
  ├─ downstream: OnError(cause) → SinkActor cancels, resolves mat with error
  └─ upstream: supervision decides:
       Restart → FlowActor restarts, re-requests demand
       Stop → cancel source, tear down stream
       Escalate → supervisor handles it
```

## GraphDSL — Complex Topologies

### Junctions

```php
Broadcast::create(int $outputPorts)    // copy to all outputs
Balance::create(int $outputPorts)      // round-robin to outputs
Merge::create(int $inputPorts)         // interleave inputs
MergePreferred::create(int $secondary) // priority input
Zip::create()                          // Pair<A,B>
ZipWith::create(callable $fn)          // combine with function
Concat::create(int $inputPorts)        // sequential
Partition::create(int $ports, callable $fn)  // route by predicate
Unzip::create()                        // Pair<A,B> → (A, B)
```

### Builder API

```php
$graph = GraphDsl::create(
    Sinks::head(),
    static function (GraphDsl\Builder $b, Inlet $sinkIn): void {
        $source = $b->add(Sources::from([1, 2, 3, 4, 5]));
        $bcast  = $b->add(Broadcast::create(2));
        $merge  = $b->add(Merge::create(2));
        $double = $b->add(Flows::map(fn(int $x): int => $x * 2));
        $triple = $b->add(Flows::map(fn(int $x): int => $x * 3));

        //              ┌─► double ─┐
        // source → bcast           merge → sink
        //              └─► triple ─┘

        $b->from($source->out)->to($bcast->in);
        $b->from($bcast->out(0))->via($double)->to($merge->in(0));
        $b->from($bcast->out(1))->via($triple)->to($merge->in(1));
        $b->from($merge->out)->to($sinkIn);
    },
);

$firstResult = $graph->run($system);
```

### Partial Graphs (Reusable Components)

```php
$doublePath = GraphDsl::createFlow(
    static function (GraphDsl\Builder $b, FlowShape $shape): void {
        $bcast = $b->add(Broadcast::create(2));
        $merge = $b->add(Merge::create(2));
        $upper = $b->add(Flows::map(fn(string $s): string => strtoupper($s)));
        $lower = $b->add(Flows::map(fn(string $s): string => strtolower($s)));

        $b->from($shape->in)->to($bcast->in);
        $b->from($bcast->out(0))->via($upper)->to($merge->in(0));
        $b->from($bcast->out(1))->via($lower)->to($merge->in(1));
        $b->from($merge->out)->to($shape->out);
    },
);

// Use as a normal flow with |>
$stream = Sources::from(['Hello', 'World'])
    |> $doublePath
    |> Sinks::seq();
```

### Graph Validation

The builder validates at construction time:
- All ports connected (no dangling inlets/outlets)
- Type safety (inlet type matches connected outlet type, Psalm enforces statically)
- Closed graphs are runnable (only `ClosedShape` can be `run()`)

## Materialization & Lifecycle

### ActorMaterializer

```php
final readonly class ActorMaterializer implements Materializer
{
    public function __construct(
        private ActorSystem $system,
        private MaterializerSettings $settings = new MaterializerSettings(),
    ) {}

    public function materialize(RunnableStream $stream): mixed
    {
        // 1. Traverse the graph blueprint
        // 2. Spawn an actor for each stage
        // 3. Wire actors (each stage knows upstream/downstream ActorRef)
        // 4. Send initial Request from sinks upstream
        // 5. Combine and return materialized values
    }
}

final readonly class MaterializerSettings
{
    public function __construct(
        public int $initialInputBufferSize = 16,
        public int $maxInputBufferSize = 16,
        public SupervisionStrategy $supervisionStrategy = /* oneForOne */,
        public Duration $subscriptionTimeout = /* 5 seconds */,
    ) {}
}
```

### Stream Lifecycle

```
Blueprint Phase:  Source |> Flow |> Sink  →  RunnableStream (no actors yet)
                                                    │
Materialization:  run($system) ────────────────────►│
                                                    │
Running Phase:    Actors spawned under StreamSupervisor
                  Demand signals flow, elements process
                                                    │
Completion:       Source exhausted → OnComplete propagates
                  OR error → OnError propagates
                  OR cancel() called on Cancellable
                                                    │
Teardown:         All stage actors stopped
                  Mat value resolved and returned
```

### StreamSupervisor

Each materialized stream gets a supervisor actor:

```
/system
  /stream-supervisor-1
    /source-1
    /map-2
    /filter-3
    /sink-4
```

The supervisor spawns all stage actors as children, applies supervision strategy, handles failures, and reports completion through the mat value.

## Built-in Stages

### Sources

```php
// Finite
Sources::from(iterable $elements)                          // Mat = void
Sources::single(mixed $element)                             // Mat = void
Sources::empty()                                            // Mat = void
Sources::failed(\Throwable $cause)                          // Mat = void
Sources::lazy(fn(): Source $factory)                        // Mat = void
Sources::unfold($seed, fn($s) => Option<Pair<$s, $elem>>)  // Mat = void

// Infinite
Sources::repeat(mixed $element)                             // Mat = void
Sources::cycle(iterable $elements)                          // Mat = void
Sources::tick(Duration $interval, mixed $element)           // Mat = Cancellable

// Interactive
Sources::queue(int $bufferSize, OverflowStrategy $strategy) // Mat = SourceQueue<T>
Sources::actorRef(int $bufferSize, fn($msg): bool $complete) // Mat = ActorRef<T>

// Combining
Sources::combine(Source ...$sources, fn: MergeStrategy)     // Mat = void
Sources::concat(Source ...$sources)                          // Mat = void
Sources::zipN(Source ...$sources)                            // Mat = void
```

### Flows

```php
// Transform
Flows::map(callable $fn)
Flows::mapAsync(int $parallelism, callable $fn)
Flows::filter(callable $predicate)
Flows::collect(callable $partialFn)
Flows::flatMapConcat(callable $fn)
Flows::flatMapMerge(int $breadth, callable $fn)
Flows::scan($zero, callable $fn)
Flows::fold($zero, callable $fn)

// Grouping
Flows::grouped(int $size)
Flows::groupedWithin(int $size, Duration $d)
Flows::sliding(int $size, int $step = 1)

// Rate control
Flows::throttle(int $elements, Duration $per)
Flows::buffer(int $size, OverflowStrategy $strategy)
Flows::debounce(Duration $duration)

// Ordering & dedup
Flows::distinctUntilChanged(?callable $comparator = null)
Flows::intersperse(mixed $separator)

// Side effects
Flows::tap(callable $fn)

// Error handling
Flows::recover(callable $fn)
Flows::recoverWithRetries(int $attempts, callable $fn)

// Lifecycle
Flows::take(int $n)
Flows::takeWhile(callable $predicate)
Flows::drop(int $n)
Flows::dropWhile(callable $predicate)
Flows::initialTimeout(Duration $timeout)
Flows::idleTimeout(Duration $timeout)
Flows::log(string $name, ?LogLevel $level)
```

### Sinks

```php
Sinks::foreach(callable $fn)          // Mat = void
Sinks::fold($zero, callable $fn)      // Mat = T
Sinks::reduce(callable $fn)           // Mat = T
Sinks::head()                         // Mat = T
Sinks::last()                         // Mat = T
Sinks::seq()                          // Mat = list<T>
Sinks::ignore()                       // Mat = void
Sinks::cancelled()                    // Mat = void
Sinks::actorRef(ActorRef $ref, object $onComplete)  // Mat = void
```

## Testing

### StepRuntime Integration

```php
$runtime = new StepRuntime();
$system = ActorSystem::create('test', $runtime);

$results = [];
$stream = Sources::from([1, 2, 3])
    |> Flows::map(fn(int $x): int => $x * 2)
    |> Sinks::foreach(function (int $x) use (&$results): void { $results[] = $x; });

$stream->run($system);
$runtime->drain();

self::assertSame([2, 4, 6], $results);
```

### TestSink and TestSource

```php
// TestSink — probe that records elements and signals
$probe = TestSink::probe($system);
$stream = Sources::from([1, 2, 3]) |> Flows::map(fn($x) => $x * 2) |> $probe;
$stream->run($system);
$probe->expectNext(2);
$probe->expectNext(4);
$probe->expectNext(6);
$probe->expectComplete();

// TestSource — push elements manually
$source = TestSource::probe($system);
$stream = $source |> Flows::map(fn($x) => $x * 2) |> Sinks::seq();
$mat = $stream->run($system);
$source->sendNext(1);
$source->sendNext(2);
$source->sendComplete();
self::assertSame([2, 4], $mat);
```

### Error Handling

Three levels:

1. **Stage-level** — `Flows::recover()` / `Flows::recoverWithRetries()` for inline error mapping
2. **Stream-level** — supervision strategy on `MaterializerSettings` for stage restart/stop decisions
3. **Mat value** — materialized value reflects success or failure of the stream

## Open Questions for Implementation

1. How should `mapAsync` resolve async results — via `ask()` to sub-actors or via direct fiber/coroutine suspension?
2. Should `SourceQueue` use a dedicated channel type or reuse the existing mailbox abstraction?
3. What Psalm plugin extensions are needed for generic type inference on stream composition?
