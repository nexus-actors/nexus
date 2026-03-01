<?php

declare(strict_types=1);

namespace Monadial\Nexus\WorkerPool\Swoole;

use Closure;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Core\Actor\StatefulActorHandler;
use Monadial\Nexus\Runtime\Mailbox\MailboxConfig;
use Monadial\Nexus\WorkerPool\WorkerNode;
use Monadial\Nexus\WorkerPool\WorkerPoolConfig;
use Psr\Log\LoggerInterface;

use function Opis\Closure\serialize as opis_serialize;

/**
 * @psalm-api
 *
 * Fluent builder for a Swoole thread-based worker pool.
 *
 * Usage:
 *
 *     WorkerPool::withThreads(8)
 *         ->withName('shop')
 *         ->actor('orders', OrderActor::class)
 *         ->behavior('pings', static fn (): Behavior => Behavior::receive(
 *             static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same()
 *         ))
 *         ->run();
 *
 * Thread safety: closures passed to behavior() and configure() MUST be static
 * and capture only serializable values. Class-string actors (actor(), stateful())
 * are always thread-safe — only strings cross thread boundaries.
 */
final class WorkerPool
{
    private string $name = 'worker';

    /** @var class-string<LoggerInterface>|null */
    private ?string $loggerClass = null;

    /** @var (Closure(): LoggerInterface)|null */
    private ?Closure $loggerFactory = null;

    /** @var (Closure(WorkerPoolHandle): void)|null */
    private ?Closure $onStart = null;

    /** @var list<Closure(WorkerNode): void> */
    private array $steps = [];

    private function __construct(private readonly int $workerCount) {}

    /**
     * Create a pool with an explicit thread count.
     */
    public static function withThreads(int $count): self
    {
        return new self($count);
    }

    /**
     * Create a pool with one thread per CPU core (swoole_cpu_num()).
     */
    public static function withCpuThreads(): self
    {
        /** @psalm-suppress ForbiddenCode */
        return new self(swoole_cpu_num());
    }

    /**
     * Set the actor system name prefix. Each worker system is named "{prefix}-{workerId}".
     * Default: "worker" → "worker-0", "worker-1", …
     */
    public function withName(string $name): self
    {
        $clone       = clone $this;
        $clone->name = $name;

        return $clone;
    }

    /**
     * Inject a PSR-3 logger by class name. Each thread calls new $loggerClass().
     * The class must have a no-argument constructor.
     *
     * @param class-string<LoggerInterface> $loggerClass
     */
    public function withLogger(string $loggerClass): self
    {
        $clone              = clone $this;
        $clone->loggerClass = $loggerClass;

        return $clone;
    }

    /**
     * Inject a PSR-3 logger via factory closure. Each thread deserializes and
     * calls the factory to create its own logger instance.
     *
     * The closure MUST be static and capture only serializable values.
     *
     * @param Closure(): LoggerInterface $factory
     */
    public function withLoggerFactory(Closure $factory): self
    {
        $clone                = clone $this;
        $clone->loggerFactory = $factory;

        return $clone;
    }

    /**
     * Register a class-based actor (implements ActorHandler).
     * The class is instantiated fresh in each thread via Props::fromFactory.
     *
     * @param class-string<ActorHandler> $actorClass
     */
    public function actor(string $name, string $actorClass, ?MailboxConfig $mailbox = null): self
    {
        $clone          = clone $this;
        $clone->steps[] = static function (WorkerNode $node) use ($name, $actorClass, $mailbox): void {
            /** @psalm-suppress MixedMethodCall */
            $props = Props::fromFactory(static fn(): ActorHandler => new $actorClass());
            $node->spawn(
                $mailbox !== null
                    ? $props->withMailbox($mailbox)
                    : $props,
                $name,
            );
        };

        return $clone;
    }

    /**
     * Register a stateful class-based actor (implements StatefulActorHandler).
     *
     * @param class-string<StatefulActorHandler> $actorClass
     */
    public function stateful(string $name, string $actorClass, ?MailboxConfig $mailbox = null): self
    {
        $clone          = clone $this;
        $clone->steps[] = static function (WorkerNode $node) use ($name, $actorClass, $mailbox): void {
            /** @psalm-suppress MixedMethodCall */
            $props = Props::fromStatefulFactory(static fn(): StatefulActorHandler => new $actorClass());
            $node->spawn(
                $mailbox !== null
                    ? $props->withMailbox($mailbox)
                    : $props,
                $name,
            );
        };

        return $clone;
    }

    /**
     * Register a behavior-based actor via a factory closure.
     *
     * The factory MUST be static. Example:
     *     ->behavior('pings', static fn (): Behavior => Behavior::receive(
     *         static fn (ActorContext $ctx, object $msg): Behavior => Behavior::same()
     *     ))
     *
     * @template T of object
     * @param Closure(): Behavior<T> $factory
     */
    public function behavior(string $name, Closure $factory, ?MailboxConfig $mailbox = null): self
    {
        $clone          = clone $this;
        $clone->steps[] = static function (WorkerNode $node) use ($name, $factory, $mailbox): void {
            $props = Props::fromBehavior($factory());
            $node->spawn(
                $mailbox !== null
                    ? $props->withMailbox($mailbox)
                    : $props,
                $name,
            );
        };

        return $clone;
    }

    /**
     * Full control: receive the WorkerNode directly to spawn actors, wire
     * dependencies, etc. The closure MUST be static.
     *
     * @param Closure(WorkerNode): void $setup
     */
    public function configure(Closure $setup): self
    {
        $clone          = clone $this;
        $clone->steps[] = $setup;

        return $clone;
    }

    /**
     * Register a main-thread callback to run after all workers are ready.
     * The closure receives a WorkerPoolHandle for message injection and stopping.
     *
     * IMPORTANT: This callback runs in the main thread (not serialized — no opis needed).
     * The closure does NOT need to be static and can capture any values.
     * Call $handle->stop() when done, or simply return (stop is called automatically).
     *
     * @param Closure(WorkerPoolHandle): void $callback
     */
    public function onStart(Closure $callback): self
    {
        $clone          = clone $this;
        $clone->onStart = $callback;

        return $clone;
    }

    /**
     * Boot the worker pool. Blocks until the pool exits.
     */
    public function run(): void
    {
        WorkerPoolBootstrap::create(
            WorkerPoolConfig::withThreads($this->workerCount)
                ->withSystemNamePrefix($this->name),
        )
            ->withSerializedConfigure($this->buildSerializedConfigure())
            ->withLoggerClass($this->loggerClass)
            ->withSerializedLoggerFactory($this->buildSerializedLoggerFactory())
            ->withOnStart($this->onStart)
            ->run();
    }

    private function buildSerializedConfigure(): ?string
    {
        if ($this->steps === []) {
            return null;
        }

        $steps    = $this->steps;
        $combined = static function (WorkerNode $node) use ($steps): void {
            foreach ($steps as $step) {
                $step($node);
            }
        };

        return opis_serialize($combined);
    }

    private function buildSerializedLoggerFactory(): ?string
    {
        if ($this->loggerFactory === null) {
            return null;
        }

        return opis_serialize($this->loggerFactory);
    }
}
