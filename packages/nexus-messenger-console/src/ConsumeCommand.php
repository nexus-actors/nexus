<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Messenger\Consumer\ReceiverActorConfig;
use Monadial\Nexus\Messenger\Consumer\UnroutablePolicy;
use Monadial\Nexus\Messenger\Lifecycle\LifecycleThresholds;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Messenger\Routing\MessageRouter;
use Monadial\Nexus\Observability\Observability;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

use function implode;
use function sprintf;

/**
 * Boots a Nexus ActorSystem with one or more ReceiverActors consuming from
 * the injected Symfony Messenger transport, then blocks until the system
 * shuts down via a limit threshold or SIGINT/SIGTERM.
 *
 * When no limit options are given the command runs indefinitely and relies on
 * SIGINT/SIGTERM (or the OS process manager) to stop it. When any limit is
 * given a LifecycleWatchdog is spawned and wired as the receivers'
 * processedListener — this is the mechanism by which processed message counts
 * flow to the watchdog so `--limit` triggers graceful shutdown.
 *
 * Example wiring inside a Symfony Console Application:
 * ```php
 * $app = new Application('nexus-worker', '1.0.0');
 * $app->add(new ConsumeCommand(
 *     new FiberRuntime(),
 *     $transport,
 *     new CallbackConsumerSetup(static function (ActorSystem $system): MessageRouter {
 *         $ref = $system->spawn(Props::fromFactory(fn() => new OrdersActor()), 'orders');
 *
 *         return new MapMessageRouter([OrderPlaced::class => $ref]);
 *     }),
 * ));
 * $app->run();
 * ```
 *
 * A plain {@see MessageRouter} is also accepted when actor refs are already available at wiring time.
 *
 * @psalm-api
 */
#[AsCommand(
    name: 'nexus:messenger:consume',
    description: 'Start a Nexus actor-system consumer for a Symfony Messenger transport.',
)]
final class ConsumeCommand extends Command implements SignalableCommandInterface
{
    private ?ActorSystem $system = null;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly ReceiverInterface $receiver,
        private readonly MessageRouter|ConsumerSetup $routing,
        private readonly ?Observability $observability = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        parent::__construct();
    }

    /**
     * @return list<int>
     */
    #[Override]
    public function getSubscribedSignals(): array
    {
        if (!extension_loaded('pcntl')) {
            return [];
        }

        return [\SIGINT, \SIGTERM];
    }

    #[Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->system?->shutdown(Duration::seconds(10));

        return false;
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('receivers', 'r', InputOption::VALUE_REQUIRED, 'Number of competing receiver actors.', 1)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after processing this many messages.')
            ->addOption(
                'memory-limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop when memory usage reaches this limit (e.g. 128M, 1G); must be > 0.',
            )
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after running for this many seconds.')
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

        $receiverCount = (int) $input->getOption('receivers');
        /** @var string|null $limitRaw */
        $limitRaw = $input->getOption('limit');
        /** @var string|null $memoryLimitRaw */
        $memoryLimitRaw = $input->getOption('memory-limit');
        /** @var string|null $timeLimitRaw */
        $timeLimitRaw = $input->getOption('time-limit');
        $pollInterval = (int) $input->getOption('poll-interval');
        $useDeadLetters = (bool) $input->getOption('dead-letters');

        $limit = $limitRaw !== null
            ? (int) $limitRaw
            : null;
        $memoryLimitBytes = $memoryLimitRaw !== null
            ? MemoryLimit::parse($memoryLimitRaw)
            : null;
        $timeLimit = $timeLimitRaw !== null
            ? (int) $timeLimitRaw
            : null;

        $this->system = ActorSystem::create(
            'nexus-messenger-consumer',
            $this->runtime,
            logger: $this->logger,
            eventDispatcher: $this->events,
            observability: $this->observability,
        );

        $router = $this->routing instanceof ConsumerSetup
            ? $this->routing->setup($this->system)
            : $this->routing;

        $watchdogRef = null;
        $hasLimits = $limit !== null || $memoryLimitBytes !== null || $timeLimit !== null;

        if ($hasLimits) {
            $thresholds = LifecycleThresholds::none();

            if ($limit !== null) {
                $thresholds = $thresholds->withMessageLimit($limit);
            }

            if ($memoryLimitBytes !== null) {
                $thresholds = $thresholds->withMemoryLimit($memoryLimitBytes);
            }

            if ($timeLimit !== null) {
                $thresholds = $thresholds->withTimeLimit(Duration::seconds($timeLimit));
            }

            $watchdogRef = $this->system->spawn(
                MessengerBridge::watchdogProps(
                    $this->system,
                    $thresholds,
                    Duration::millis(200),
                ),
                'watchdog',
            );
        }

        $config = ReceiverActorConfig::default()->withPollInterval(Duration::millis($pollInterval));

        if ($useDeadLetters) {
            $config = $config->withUnroutablePolicy(UnroutablePolicy::DeadLetters);
        }

        $deadLettersRef = $useDeadLetters
            ? $this->system->deadLetters()
            : null;

        MessengerBridge::spawnReceivers(
            $this->system,
            $receiverCount,
            'receiver',
            $this->receiver,
            $router,
            $config,
            $deadLettersRef,
            $watchdogRef,
            $this->events,
            $this->observability,
        );

        $limitParts = [];

        if ($limit !== null) {
            $limitParts[] = "{$limit} messages";
        }

        if ($memoryLimitRaw !== null) {
            $limitParts[] = "{$memoryLimitRaw} memory";
        }

        if ($timeLimit !== null) {
            $limitParts[] = "{$timeLimit}s uptime";
        }

        $limitDesc = $limitParts !== []
            ? 'limits: ' . implode(', ', $limitParts)
            : 'no limits — Ctrl-C to stop';

        $io->success(sprintf(
            'Starting %d receiver(s) on %s (%s).',
            $receiverCount,
            $this->receiver::class,
            $limitDesc,
        ));

        $this->system->run();

        $io->success('Consumer stopped.');

        return Command::SUCCESS;
    }
}
