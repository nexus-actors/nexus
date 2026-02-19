import React from 'react';
import Layout from '@theme/Layout';
import Link from '@docusaurus/Link';
import CodeBlock from '@theme/CodeBlock';
import styles from './index.module.css';

/* ------------------------------------------------------------------ */
/* Code examples                                                       */
/* ------------------------------------------------------------------ */

const heroCode = `// Messages are plain readonly classes
readonly class Increment {}

// Stateful actor: receives messages, returns next state
$counter = Behavior::withState(0, function (
    ActorContext $ctx, object $msg, int $count,
): BehaviorWithState {
    if ($msg instanceof Increment) {
        $ctx->log()->info("count: " . ($count + 1));
        return BehaviorWithState::next($count + 1);
    }
    return BehaviorWithState::same();
});

// Create system, spawn actor, send messages
$system = ActorSystem::create('app', new FiberRuntime());
$ref = $system->spawn(Props::fromBehavior($counter), 'counter');
$ref->tell(new Increment());
$ref->tell(new Increment());
$ref->tell(new Increment());
$system->run();`;

const supervisionCode = `// When an actor fails, its parent decides what happens.
// No try/catch. No manual retry logic. Just policy.

$strategy = SupervisionStrategy::exponentialBackoff(
    initialBackoff: Duration::millis(100),
    maxBackoff:     Duration::seconds(30),
    maxRetries:     5,
    multiplier:     2.0,
    decider: static fn (Throwable $e) => match (true) {
        $e instanceof TransientError => Directive::Restart,
        $e instanceof FatalError     => Directive::Stop,
        default                      => Directive::Escalate,
    },
);

$props = Props::fromBehavior($behavior)
    ->withSupervision($strategy)
    ->withMailbox(MailboxConfig::bounded(10_000));`;

const classActorCode = `// Class-based actors with lifecycle hooks
// and PSR-11 dependency injection.

final class OrderProcessor extends AbstractActor
{
    public function __construct(
        private readonly PaymentGateway $payments,
        private readonly Inventory $inventory,
    ) {}

    public function onPreStart(ActorContext $ctx): void
    {
        $ctx->log()->info('OrderProcessor started');
    }

    public function handle(
        ActorContext $ctx, object $message,
    ): Behavior {
        if ($message instanceof ProcessOrder) {
            $this->payments->charge($message->order);
            $this->inventory->reserve($message->items);
            $message->replyTo->tell(new OrderConfirmed(
                orderId: $message->orderId,
            ));
        }

        return Behavior::same();
    }
}

// Spawn from your DI container
$ref = $system->spawn(
    Props::fromContainer($container, OrderProcessor::class),
    'order-processor',
);`;

const runtimeCode = `// Development: PHP Fibers. Zero extensions needed.
$system = ActorSystem::create('dev', new FiberRuntime());

// Production: Swoole coroutines. 100K+ concurrent actors.
$system = ActorSystem::create('prod', new SwooleRuntime(
    new SwooleConfig(
        maxCoroutines: 100_000,
        enableCoroutineHook: true,
    ),
));

// Your actor code doesn't change. Not a single line.
$ref = $system->spawn(Props::fromBehavior($behavior), 'worker');
$ref->tell(new ProcessTask($payload));`;

const eventSourcingCode = `// Events are immutable facts. State rebuilds from history.
// Crash? Restart? State recovers automatically.

#[MessageType('account.deposited')]
readonly class Deposited {
    public function __construct(public float $amount) {}
}

$account = EventSourcedBehavior::create(
    persistenceId: PersistenceId::of('account', 'ACC-001'),
    emptyState: new AccountState(balance: 0.0),
    commandHandler: function (AccountState $state, ActorContext $ctx, object $cmd)
        : Effect
    {
        if ($cmd instanceof Deposit) {
            return Effect::persist(new Deposited($cmd->amount))
                ->thenReply($cmd->replyTo,
                    fn ($s) => new Balance($s->balance));
        }
        return Effect::unhandled();
    },
    eventHandler: function (AccountState $state, object $event)
        : AccountState
    {
        return match (true) {
            $event instanceof Deposited => new AccountState(
                balance: $state->balance + $event->amount,
            ),
            default => $state,
        };
    },
)
->withSnapshotStrategy(SnapshotStrategy::everyN(100))
->withEventStore($eventStore)
->toBehavior();`;

const stashingCode = `// Actors can buffer messages during initialization.
// When ready, unstash and process them in order.

$initializing = Behavior::receive(
    static function (ActorContext $ctx, object $msg)
        use ($ready): Behavior
    {
        if ($msg instanceof DatabaseReady) {
            // Connection established. Process buffered work.
            $ctx->unstashAll();
            return $ready;
        }

        // Not ready yet. Buffer this message.
        $ctx->stash();
        return Behavior::same();
    },
);

$ref = $system->spawn(Props::fromBehavior($initializing), 'db-writer');

// These arrive before the database is ready.
// They're stashed, then replayed in order.
$ref->tell(new WriteRecord($data1));
$ref->tell(new WriteRecord($data2));
$ref->tell(new DatabaseReady($connection));`;

const scalingCode = `// One machine, all CPU cores. Actors span processes transparently.
// Same ActorRef API -- senders don't know if it's local or remote.

$cluster = ClusterBootstrap::create(
    ClusterConfig::withWorkers(16),
)
->onWorkerStart(function (ClusterNode $node): void {
    // Each worker runs an independent ActorSystem.
    // The hash ring decides which worker owns which actor.
    $node->spawn(
        Props::fromBehavior($orderBehavior),
        'order-processor',
    );
})
->run();

// Cross-worker messaging uses Unix domain sockets.
// 255K msgs/sec per worker pair. Zero config.`;

/* ------------------------------------------------------------------ */
/* Data                                                                */
/* ------------------------------------------------------------------ */

const pillars = [
  {
    title: 'Concurrent by Design',
    desc: 'Each actor is a lightweight unit of computation with its own mailbox and state. No shared memory. No locks. No race conditions. Thousands of actors run concurrently, communicating only through immutable messages.',
  },
  {
    title: 'Fault-Tolerant by Default',
    desc: 'When something fails -- and it will -- the supervision hierarchy handles it automatically. Parent actors decide whether to restart, stop, or escalate failures. Your system recovers without human intervention.',
  },
  {
    title: 'Type-Safe at Every Layer',
    desc: 'Every ActorRef carries its message type as a generic parameter. Send the wrong message type and Psalm catches it during analysis -- not at 3 AM in production. The entire API is built around readonly classes and immutable value objects.',
  },
];

const features = [
  {
    title: 'Stateful Actors',
    description: 'Manage state explicitly with Behavior::withState(). State transitions are pure functions. No hidden side effects, no shared globals.',
    icon: '\u03BB',
  },
  {
    title: 'Supervision Strategies',
    description: 'One-for-one, all-for-one, and exponential backoff. Custom decider functions per exception type. Automatic retry with configurable limits.',
    icon: '\u25B3',
  },
  {
    title: 'Message Stashing',
    description: 'Buffer messages during transitional states. Unstash when ready. Messages replay in order. Perfect for initialization sequences.',
    icon: '\u29D6',
  },
  {
    title: 'Scheduled Messages',
    description: 'One-shot and repeating timers with cancellation. Schedule messages to self or other actors. Nanosecond-precision Duration values.',
    icon: '\u23F1',
  },
  {
    title: 'Actor Hierarchies',
    description: 'Actors spawn children. Children have paths like /user/orders/order-123. Watch actors for termination signals. Full lifecycle management.',
    icon: '\u2387',
  },
  {
    title: 'Dead Letter Office',
    description: 'Messages sent to stopped actors are captured, not silently dropped. Inspect dead letters for debugging. No message goes unaccounted for.',
    icon: '\u2709',
  },
  {
    title: 'Immutable Everything',
    description: 'Readonly classes, immutable behaviors, value objects everywhere. State is passed, not mutated. Static analysis enforces discipline your team can trust.',
    icon: '\u2205',
  },
  {
    title: 'Event Sourcing',
    description: 'Persist events, rebuild state from history. Automatic crash recovery. Configurable snapshot strategies and retention policies. DBAL and Doctrine backends.',
    icon: '\u25C9',
  },
  {
    title: 'Durable State',
    description: 'Simpler alternative to event sourcing. Persist the latest state directly. Same actor lifecycle integration, same backend options, less ceremony.',
    icon: '\u25A3',
  },
  {
    title: 'Multi-Process Scaling',
    description: 'Scale across all CPU cores with ClusterBootstrap and Swoole Process\\Pool. Consistent hash ring for actor placement. Unix socket transport at 255K msgs/sec.',
    icon: '\u2B21',
  },
  {
    title: 'Deterministic Testing',
    description: 'StepRuntime and VirtualClock let you control time and execution order. Write tests that are fast, deterministic, and free of timing flakiness.',
    icon: '\u2316',
  },
  {
    title: 'PSR Integration',
    description: 'PSR-11 containers for DI, PSR-3 logging, PSR-14 event dispatching, PSR-20 clocks. Nexus works with your existing stack, not against it.',
    icon: '\u2713',
  },
];

/* ------------------------------------------------------------------ */
/* Components                                                          */
/* ------------------------------------------------------------------ */

function Hero() {
  return (
    <section className={styles.hero}>
      <div className={styles.heroGrid} />
      <div className={styles.heroSplit}>
        <div className={styles.heroLeft}>
          <div className={styles.heroBadge}>
            WIP &middot; Open source &middot; PHP 8.5+
          </div>
          <h1 className={styles.heroTitle}>
            Concurrent PHP,<br />
            <span className={styles.heroAccent}>done right.</span>
          </h1>
          <p className={styles.heroTagline}>
            Type-safe actors, supervision trees, event sourcing, multi-process scaling, pluggable runtimes.
            Erlang/OTP and Akka patterns — in the PHP you already know.
          </p>
          <div className={styles.heroCta}>
            <Link className={styles.ctaPrimary} to="/docs/getting-started/quick-start">
              Quick Start
            </Link>
            <Link className={styles.ctaGhost} to="/docs/intro">
              Docs
            </Link>
            <Link
              className={styles.ctaGhost}
              href="https://github.com/monadial/nexus"
            >
              GitHub
            </Link>
          </div>
          <div className={styles.heroInstall}>
            <code>composer require monadial/nexus</code>
          </div>
        </div>
        <div className={styles.heroRight}>
          <div className={styles.heroCodeWrap}>
            <CodeBlock language="php" title="demo.php" showLineNumbers>
              {heroCode}
            </CodeBlock>
          </div>
        </div>
      </div>
    </section>
  );
}

function Problem() {
  return (
    <section className={styles.problem}>
      <div className={styles.sectionInner}>
        <h2 className={styles.sectionTitle}>
          PHP can handle HTTP.<br />
          But what about everything else?
        </h2>
        <div className={styles.problemGrid}>
          <div className={styles.problemCard}>
            <h3 className={styles.problemCardTitle}>The queue worker trap</h3>
            <p className={styles.problemCardText}>
              You start with a simple Redis queue. Then you need retries. Then
              error handling. Then state management. Then monitoring. Six months
              later, you've built half of Erlang/OTP -- poorly -- spread across
              a dozen worker scripts.
            </p>
          </div>
          <div className={styles.problemCard}>
            <h3 className={styles.problemCardTitle}>No structure for concurrency</h3>
            <p className={styles.problemCardText}>
              PHP has fibers and coroutines now, but no framework for using
              them safely. No supervision. No message protocols. No way to
              reason about concurrent state. Raw concurrency primitives are
              like raw SQL -- powerful and dangerous.
            </p>
          </div>
          <div className={styles.problemCard}>
            <h3 className={styles.problemCardTitle}>The language barrier</h3>
            <p className={styles.problemCardText}>
              Teams are told to "just use Go" or "switch to Elixir" for
              concurrent workloads. But your domain knowledge, your team's
              expertise, and your existing codebase are all in PHP. You
              shouldn't have to rewrite everything.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

function Pillars() {
  return (
    <section className={styles.pillars}>
      <div className={styles.sectionInner}>
        <h2 className={styles.sectionTitle}>Built on proven principles</h2>
        <p className={styles.sectionSub}>
          The actor model has powered telecom switches, stock exchanges, and
          social networks for decades. Nexus brings the same patterns to PHP.
        </p>
        <div className={styles.pillarGrid}>
          {pillars.map((p, i) => (
            <div key={i} className={styles.pillarCard}>
              <div className={styles.pillarNumber}>0{i + 1}</div>
              <h3 className={styles.pillarTitle}>{p.title}</h3>
              <p className={styles.pillarDesc}>{p.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function ShowcaseSection({ title, description, code, codeTitle, reversed }) {
  return (
    <section className={`${styles.showcase} ${reversed ? styles.showcaseReversed : ''}`}>
      <div className={styles.showcaseInner}>
        <div className={styles.showcaseText}>
          <h2 className={styles.showcaseTitle}>{title}</h2>
          <p className={styles.showcaseDesc}>{description}</p>
        </div>
        <div className={styles.showcaseCode}>
          <CodeBlock language="php" title={codeTitle} showLineNumbers>
            {code}
          </CodeBlock>
        </div>
      </div>
    </section>
  );
}

function Showcases() {
  return (
    <div className={styles.showcases}>
      <div className={styles.showcasesDivider}>
        <h2 className={styles.sectionTitle}>See it in action</h2>
        <p className={styles.sectionSub}>
          Real patterns, real code. Every example runs as-is.
        </p>
      </div>

      <ShowcaseSection
        title="Supervision that handles failure for you"
        description="Actors fail. Networks drop. Databases time out. Instead of wrapping everything in try/catch, define a supervision strategy once. The parent actor applies it automatically -- restart with backoff, stop permanently, or escalate to the next level. Your business logic stays clean."
        code={supervisionCode}
        codeTitle="supervision.php"
      />

      <ShowcaseSection
        title="Class-based actors with dependency injection"
        description="Not everything fits in a closure. For complex actors, extend AbstractActor and get lifecycle hooks (onPreStart, onPostStop), constructor injection via PSR-11 containers, and clean separation of concerns. Spawn them from your DI container with a single line."
        code={classActorCode}
        codeTitle="OrderProcessor.php"
        reversed
      />

      <ShowcaseSection
        title="One codebase, two runtimes"
        description="Develop locally with the Fiber runtime -- zero extensions required, instant startup, built into PHP. Deploy to production with the Swoole runtime -- true coroutines, native channels, 100K+ concurrent actors. Switch runtimes by changing a single constructor. Your actor code stays identical."
        code={runtimeCode}
        codeTitle="runtime.php"
      />

      <ShowcaseSection
        title="Stash now, process later"
        description="Some actors need to initialize before handling work -- connecting to a database, loading configuration, warming caches. With stashing, incoming messages are buffered transparently. When the actor is ready, unstash replays them in order. No messages lost, no timing hacks."
        code={stashingCode}
        codeTitle="stashing.php"
        reversed
      />

      <ShowcaseSection
        title="Event sourcing, built into the actor model"
        description="Persist events, not state. Every state change is captured as an immutable fact. Actors rebuild their state from event history on startup -- crash recovery is automatic. Snapshot strategies keep recovery fast. Swap between DBAL and Doctrine stores with zero code changes."
        code={eventSourcingCode}
        codeTitle="event-sourcing.php"
      />

      <ShowcaseSection
        title="Scale across all CPU cores"
        description="ClusterBootstrap starts a Swoole Process\Pool where each worker runs an independent ActorSystem. A consistent hash ring decides actor placement. Cross-worker messaging uses Unix domain sockets at 255K msgs/sec. Your actor code stays exactly the same -- only the deployment topology changes."
        code={scalingCode}
        codeTitle="cluster.php"
        reversed
      />
    </div>
  );
}

function Features() {
  return (
    <section className={styles.features}>
      <div className={styles.sectionInner}>
        <h2 className={styles.sectionTitle}>Everything you need</h2>
        <p className={styles.sectionSub}>
          A complete toolkit for building concurrent PHP systems.
        </p>
        <div className={styles.featuresGrid}>
          {features.map((f, i) => (
            <div key={i} className={styles.featureCard}>
              <span className={styles.featureIcon}>{f.icon}</span>
              <h3 className={styles.featureTitle}>{f.title}</h3>
              <p className={styles.featureDesc}>{f.description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function HowItWorks() {
  const steps = [
    {
      step: '01',
      title: 'Define your messages',
      desc: 'Messages are plain readonly classes. No interfaces to implement, no serialization boilerplate. Just data.',
      code: `readonly class PlaceOrder {\n    public function __construct(\n        public string $orderId,\n        public array $items,\n        public ActorRef $replyTo,\n    ) {}\n}`,
    },
    {
      step: '02',
      title: 'Write a behavior',
      desc: 'A behavior is a pure function: it receives a message and returns the next behavior. Stateless or stateful — your choice.',
      code: `$handler = Behavior::receive(\n    static function (ActorContext $ctx, object $msg)\n        : Behavior\n    {\n        if ($msg instanceof PlaceOrder) {\n            // process the order\n            $msg->replyTo->tell(new OrderPlaced(\n                $msg->orderId,\n            ));\n        }\n        return Behavior::same();\n    },\n);`,
    },
    {
      step: '03',
      title: 'Spawn actors',
      desc: 'Actors are spawned from Props — a configuration object that binds a behavior to a mailbox and supervision strategy.',
      code: `$props = Props::fromBehavior($handler)\n    ->withMailbox(MailboxConfig::bounded(5000))\n    ->withSupervision(\n        SupervisionStrategy::exponentialBackoff(\n            initialBackoff: Duration::millis(100),\n            maxBackoff: Duration::seconds(30),\n        ),\n    );\n\n$ref = $system->spawn($props, 'order-service');`,
    },
    {
      step: '04',
      title: 'Send messages and run',
      desc: 'Tell an actor to do something. The runtime handles scheduling, mailbox delivery, and concurrency. You focus on business logic.',
      code: `$ref->tell(new PlaceOrder(\n    orderId: 'ORD-2024-001',\n    items: ['widget-a', 'widget-b'],\n    replyTo: $confirmationActor,\n));\n\n// Start the event loop\n$system->run();`,
    },
  ];

  return (
    <section className={styles.howItWorks}>
      <div className={styles.sectionInner}>
        <h2 className={styles.sectionTitle}>Four steps to your first actor</h2>
        <p className={styles.sectionSub}>
          No framework magic. No code generation. Just straightforward PHP.
        </p>
        <div className={styles.stepsGrid}>
          {steps.map((s, i) => (
            <div key={i} className={styles.stepCard}>
              <div className={styles.stepHeader}>
                <span className={styles.stepNumber}>{s.step}</span>
                <h3 className={styles.stepTitle}>{s.title}</h3>
              </div>
              <p className={styles.stepDesc}>{s.desc}</p>
              <div className={styles.stepCode}>
                <CodeBlock language="php">{s.code}</CodeBlock>
              </div>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function UseCases() {
  const cases = [
    {
      title: 'Event-Driven Microservices',
      desc: 'Replace sprawling queue consumers with actors that own their state and communicate through typed messages. Each service is an actor — or a tree of actors — with built-in fault recovery.',
    },
    {
      title: 'Real-Time Data Pipelines',
      desc: 'Ingest, transform, and route high-volume data streams. Actors process messages concurrently with backpressure-aware mailboxes. No data loss, no manual flow control.',
    },
    {
      title: 'Task Orchestration',
      desc: 'Coordinate multi-step workflows where each step may fail independently. Parent actors supervise workers, retry transient failures, and escalate permanent ones — automatically.',
    },
    {
      title: 'IoT and Device Management',
      desc: 'One actor per device. Thousands of concurrent connections, each with its own state and lifecycle. Actors start when devices connect and stop cleanly when they disconnect.',
    },
    {
      title: 'Financial Transaction Processing',
      desc: 'Process payments, transfers, and settlements with event-sourced actors. Every transaction is an immutable event with full audit trail. Actors maintain account balances without database locks.',
    },
    {
      title: 'Game Servers and Simulations',
      desc: 'Model game entities as actors. Players, rooms, NPCs — each with independent state and behavior. Concurrent updates without shared memory or mutex contention.',
    },
  ];

  return (
    <section className={styles.useCases}>
      <div className={styles.sectionInner}>
        <h2 className={styles.sectionTitle}>Designed for real work</h2>
        <p className={styles.sectionSub}>
          Nexus is under active development. These are the use cases<br />
          we're building towards.
        </p>
        <div className={styles.useCasesGrid}>
          {cases.map((c, i) => (
            <div key={i} className={styles.useCaseCard}>
              <h3 className={styles.useCaseTitle}>{c.title}</h3>
              <p className={styles.useCaseDesc}>{c.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

function Philosophy() {
  return (
    <section className={styles.philosophy}>
      <div className={styles.sectionInner}>
        <div className={styles.philoGrid}>
          <div className={styles.philoMain}>
            <h2 className={styles.philoTitle}>
              We didn't invent the actor model.<br />
              We brought it home to PHP.
            </h2>
            <p className={styles.philoText}>
              The actor model was conceived in 1973. Erlang proved it could run
              telecom systems with 99.9999999% uptime. Akka scaled it to millions
              of concurrent users on the JVM. These aren't experimental ideas —
              they're battle-tested patterns with decades of production validation.
            </p>
            <p className={styles.philoText}>
              PHP teams have been locked out of these patterns. Not because the
              language can't handle it — PHP 8.5 has fibers, readonly classes,
              enums, and first-class callable syntax. The missing piece was a
              framework that takes these primitives seriously.
            </p>
            <p className={styles.philoText}>
              Nexus fills that gap. Every design decision serves a single goal:
              let PHP developers build concurrent systems with the same
              confidence that Erlang and Akka developers have enjoyed for years.
            </p>
          </div>
          <div className={styles.philoSide}>
            <div className={styles.philoValue}>
              <h4 className={styles.philoValueTitle}>Explicit over implicit</h4>
              <p className={styles.philoValueText}>
                State is passed as function arguments, not hidden in object
                properties. Behaviors are returned, not mutated. You can read
                any actor handler and understand it completely.
              </p>
            </div>
            <div className={styles.philoValue}>
              <h4 className={styles.philoValueTitle}>Let it crash</h4>
              <p className={styles.philoValueText}>
                Don't write defensive code against every possible failure.
                Write actors that handle the happy path. Let the supervision
                hierarchy handle everything else.
              </p>
            </div>
            <div className={styles.philoValue}>
              <h4 className={styles.philoValueTitle}>Composition over inheritance</h4>
              <p className={styles.philoValueText}>
                Behaviors compose. Props compose. Supervision strategies
                compose. Small, focused pieces snap together into complex
                systems. No deep class hierarchies.
              </p>
            </div>
            <div className={styles.philoValue}>
              <h4 className={styles.philoValueTitle}>No magic</h4>
              <p className={styles.philoValueText}>
                No annotations that generate code. No runtime reflection.
                No hidden service locators. Every behavior is a plain
                function. Every message is a plain class.
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

function Architecture() {
  return (
    <section className={styles.architecture}>
      <div className={styles.sectionInner}>
        <h2 className={styles.sectionTitle}>Modular by design</h2>
        <p className={styles.sectionSub}>
          Pick what you need. Leave what you don't.
        </p>
        <div className={styles.archGrid}>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-core</code>
            <p className={styles.archDesc}>
              Actors, behaviors, supervision, mailboxes, and the full type-safe
              API. Runtime-agnostic. Zero dependencies beyond PSR interfaces.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-runtime-fiber</code>
            <p className={styles.archDesc}>
              Fiber-based runtime. No extensions. Cooperative multitasking
              with PHP's native fiber scheduler. Ideal for development and testing.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-runtime-swoole</code>
            <p className={styles.archDesc}>
              Swoole coroutine runtime. Native channels, true async I/O,
              100K+ concurrent actors. Built for production workloads.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-serialization</code>
            <p className={styles.archDesc}>
              Message serialization with envelope protocol. PHP native
              serializer for speed, Valinor mapper for structured wire formats.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-persistence</code>
            <p className={styles.archDesc}>
              Event sourcing and durable state abstractions. Effects, snapshots,
              retention policies, concurrency control, and in-memory stores for testing.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-persistence-dbal</code>
            <p className={styles.archDesc}>
              Doctrine DBAL storage backends. SQL-backed event, snapshot, and
              durable state stores. Works with SQLite, PostgreSQL, MySQL.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-persistence-doctrine</code>
            <p className={styles.archDesc}>
              Doctrine ORM adapter. Entity-based stores using EntityManager.
              Same table schema as the DBAL package.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-cluster</code>
            <p className={styles.archDesc}>
              Pure PHP abstractions for scaling: consistent hash ring, remote
              actor refs, pluggable transport and directory interfaces.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-cluster-swoole</code>
            <p className={styles.archDesc}>
              Swoole multi-process scaling. ClusterBootstrap, Unix socket
              transport, shared-memory actor directory via Swoole\Table.
            </p>
          </div>
          <div className={styles.archCard}>
            <code className={styles.archPkg}>monadial/nexus-psalm</code>
            <p className={styles.archDesc}>
              Psalm plugin for static analysis of actor message protocols.
              Type providers and rules that catch errors before runtime.
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

function BottomCta() {
  return (
    <section className={styles.bottomCta}>
      <div className={styles.heroGrid} />
      <div className={styles.bottomCtaInner}>
        <h2 className={styles.bottomCtaTitle}>
          Try it in five minutes.
        </h2>
        <p className={styles.bottomCtaSub}>
          Install, create demo.php, and run it. That's the whole setup.
        </p>
        <div className={styles.bottomCtaCode}>
          <code>composer require monadial/nexus-core monadial/nexus-runtime-fiber && php demo.php</code>
        </div>
        <div className={styles.heroCta}>
          <Link className={styles.ctaPrimary} to="/docs/getting-started/quick-start">
            Quick Start Guide
          </Link>
          <Link className={styles.ctaGhost} to="/docs/core-concepts/actors">
            Explore the API
          </Link>
        </div>
      </div>
    </section>
  );
}

/* ------------------------------------------------------------------ */
/* Page                                                                */
/* ------------------------------------------------------------------ */

export default function Home() {
  return (
    <Layout
      title="Concurrent PHP, done right"
      description="Nexus is an actor system for PHP 8.5+ with type-safe actors, supervision trees, event sourcing, and pluggable runtimes. Work in progress."
    >
      <main className={styles.landing}>
        <Hero />
        <Problem />
        <Pillars />
        <Showcases />
        <Features />
        <HowItWorks />
        <UseCases />
        <Philosophy />
        <Architecture />
        <BottomCta />
      </main>
    </Layout>
  );
}
