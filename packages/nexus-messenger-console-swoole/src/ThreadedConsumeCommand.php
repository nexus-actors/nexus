<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console\Swoole;

use InvalidArgumentException;
use Monadial\Nexus\Messenger\Console\MemoryLimit;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\WorkerPool\Swoole\WorkerPoolBootstrap;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;
use function is_a;
use function Opis\Closure\serialize as opis_serialize;
use function sprintf;

/**
 * Threaded consume command for the Nexus messenger bridge.
 *
 * Boots a Swoole thread-based worker pool where each thread owns:
 *  - an independent ActorSystem + SwooleRuntime
 *  - a fresh transport connection (via {@see ThreadedConsumerBootstrap::receiver()})
 *  - N competing ReceiverActors polling that connection
 *
 * The broker naturally load-balances: each thread's consumers compete only
 * within that thread; threads compete with each other across the broker queue.
 *
 * **Limit options are per-thread.** Each thread has its own LifecycleWatchdog;
 * when it fires, that thread's ActorSystem shuts down. Setting `--limit=1000`
 * on a 4-thread pool means each thread processes up to 1000 messages before
 * recycling, not 1000 total across all threads.
 *
 * **Signal handling:** The main thread blocks inside `Swoole\Thread\Pool::start()`.
 * SIGTERM/SIGINT reaches the main process; the OS delivers it, the pool exits,
 * and each worker thread shuts down with its ActorSystem. No
 * `SignalableCommandInterface` is used in v1 — rely on the process manager to
 * stop the worker.
 *
 * Usage:
 * ```php
 * $app->add(new ThreadedConsumeCommand(OrderConsumerBootstrap::class));
 * ```
 *
 * @psalm-api
 */
#[AsCommand(
    name: 'nexus:messenger:consume-threads',
    description: 'Start a threaded Nexus actor-system consumer using the Swoole worker pool.',
)]
final class ThreadedConsumeCommand extends Command
{
    /**
     * @param class-string<ThreadedConsumerBootstrap> $bootstrapClass
     */
    public function __construct(
        private readonly string $bootstrapClass,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if (!is_a($bootstrapClass, ThreadedConsumerBootstrap::class, true)) {
            throw new InvalidArgumentException(sprintf(
                '%s must implement %s.',
                $bootstrapClass,
                ThreadedConsumerBootstrap::class,
            ));
        }

        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('threads', 't', InputOption::VALUE_REQUIRED, 'Number of worker threads.', 2)
            ->addOption(
                'receivers',
                'r',
                InputOption::VALUE_REQUIRED,
                'Number of competing receiver actors per thread.',
                1,
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop each thread after processing this many messages.',
            )
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop each thread when memory usage reaches this limit (e.g. 128M, 1G); must be > 0.',
            )
            ->addOption(
                'time-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop each thread after running for this many seconds.',
            )
            ->addOption(
                'poll-interval',
                null,
                InputOption::VALUE_REQUIRED,
                'Receiver poll interval in milliseconds.',
                100,
            )
            ->addOption(
                'dead-letters',
                null,
                InputOption::VALUE_NONE,
                'Forward unroutable messages to dead letters instead of rejecting them.',
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $threads        = (int) $input->getOption('threads');
        $receivers      = (int) $input->getOption('receivers');
        /** @var string|null $limitRaw */
        $limitRaw       = $input->getOption('limit');
        /** @var string|null $memoryLimitRaw */
        $memoryLimitRaw = $input->getOption('memory-limit');
        /** @var string|null $timeLimitRaw */
        $timeLimitRaw   = $input->getOption('time-limit');
        $pollMs         = (int) $input->getOption('poll-interval');
        $deadLetters    = (bool) $input->getOption('dead-letters');

        $limit       = $limitRaw !== null
            ? (int) $limitRaw
            : null;
        $memoryBytes = $memoryLimitRaw !== null
            ? MemoryLimit::parse($memoryLimitRaw)
            : null;
        $timeSeconds = $timeLimitRaw !== null
            ? (int) $timeLimitRaw
            : null;

        $bootstrapClass = $this->bootstrapClass;

        // CRITICAL: The configure closure must be static and capture ONLY scalars
        // and class-strings. Live objects (logger, transport, router) cannot cross
        // thread boundaries via opis/closure — each thread constructs its own.
        $configure = static function (WorkerNode $node) use (
            $bootstrapClass,
            $receivers,
            $limit,
            $memoryBytes,
            $timeSeconds,
            $pollMs,
            $deadLetters,
        ): void {
            $bootstrap = new $bootstrapClass();
            $system    = $node->system();
            $router    = $bootstrap->setup($system);
            $receiver  = $bootstrap->receiver();

            $watchdogRef = null;
            $hasLimits   = $limit !== null || $memoryBytes !== null || $timeSeconds !== null;

            if ($hasLimits) {
                $thresholds = LifecycleThresholds::none();

                if ($limit !== null) {
                    $thresholds = $thresholds->withMessageLimit($limit);
                }

                if ($memoryBytes !== null) {
                    $thresholds = $thresholds->withMemoryLimit($memoryBytes);
                }

                if ($timeSeconds !== null) {
                    $thresholds = $thresholds->withTimeLimit(Duration::seconds($timeSeconds));
                }

                $watchdogRef = $system->spawn(
                    MessengerBridge::watchdogProps($system, $thresholds, Duration::millis(200)),
                    'watchdog',
                );
            }

            $config = ReceiverActorConfig::default()->withPollInterval(Duration::millis($pollMs));

            $deadLettersRef = null;

            if ($deadLetters) {
                $config         = $config->withUnroutablePolicy(UnroutablePolicy::DeadLetters);
                $deadLettersRef = $system->deadLetters();
            }

            MessengerBridge::spawnReceivers(
                $system,
                $receivers,
                'receiver',
                $receiver,
                $router,
                $config,
                $deadLettersRef,
                $watchdogRef,
            );
        };

        $limitParts = [];

        if ($limit !== null) {
            $limitParts[] = "{$limit} messages";
        }

        if ($memoryLimitRaw !== null) {
            $limitParts[] = "{$memoryLimitRaw} memory";
        }

        if ($timeSeconds !== null) {
            $limitParts[] = "{$timeSeconds}s uptime";
        }

        $limitDesc = $limitParts !== []
            ? 'limits per thread: ' . implode(', ', $limitParts)
            : 'no limits — stop via SIGTERM';

        $io->success(sprintf(
            'Starting %d thread(s), %d receiver(s) per thread on %s (%s).',
            $threads,
            $receivers,
            $bootstrapClass,
            $limitDesc,
        ));

        WorkerPoolBootstrap::create(
            WorkerPoolConfig::withThreads($threads)->withSystemNamePrefix('messenger-consumer'),
        )
            ->withSerializedConfigure(opis_serialize($configure))
            ->run();

        $io->success('Consumer stopped.');

        return Command::SUCCESS;
    }
}
