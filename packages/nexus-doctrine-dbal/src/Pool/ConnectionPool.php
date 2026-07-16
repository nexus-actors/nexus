<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Dbal\Pool;

use Closure;
use Doctrine\DBAL\Connection;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionCreated;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionDestroyed;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionPoisoned as ConnectionPoisonedEvent;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionReleased;
use Monadial\Nexus\Doctrine\Dbal\Event\ConnectionTaken;
use Monadial\Nexus\Doctrine\Dbal\Event\PoolExhausted as PoolExhaustedEvent;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\Channel;
use Monadial\Nexus\Runtime\Duration;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplObjectStorage;
use Throwable;

use function sprintf;

/**
 * @psalm-api
 *
 * @psalm-type BorrowMeta = array{takenAt: int}
 */
final class ConnectionPool
{
    /** @var Channel<Connection> */
    private Channel $idle;

    /** @var SplObjectStorage<Connection, BorrowMeta> */
    private SplObjectStorage $inUse;

    /** @var array<int, int> */
    private array $idleSince = [];
    private int $total = 0;
    private int $totalBorrows = 0;
    private int $totalWaits = 0;
    private int $totalTimeouts = 0;
    private int $waitingCoroutines = 0;
    private bool $closed = false;

    /**
     * @param Channel<Connection> $channel
     */
    public function __construct(
        private readonly string $name,
        private readonly ConnectionFactory $factory,
        private readonly PoolConfig $config,
        Channel $channel,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->idle = $channel;
        /** @var SplObjectStorage<Connection, BorrowMeta> $inUse */
        $inUse = new SplObjectStorage();
        $this->inUse = $inUse;
    }

    public function take(?Duration $timeout = null): Connection
    {
        if ($this->closed) {
            throw new PoolClosedException($this->name);
        }

        $startNanos = hrtime(true);
        $existing = $this->idle->pop(Duration::zero());

        if ($existing !== null) {
            return $this->markBorrowed($existing, $startNanos);
        }

        if ($this->total < $this->config->max) {
            $created = $this->factory->create();
            $this->total++;

            if ($this->events !== null) {
                $this->events->dispatch(new ConnectionCreated($this->name));
            }

            return $this->markBorrowed($created, $startNanos);
        }

        return $this->waitForRelease($timeout ?? $this->config->borrowTimeout, $startNanos);
    }

    public function release(Connection $conn, bool $poison = false): void
    {
        if (!$this->inUse->offsetExists($conn)) {
            return;
        }

        $meta = $this->inUse[$conn];
        $heldNanos = hrtime(true) - $meta['takenAt'];
        $this->inUse->offsetUnset($conn);

        if ($poison || $this->closed) {
            $this->total--;
            $this->safeClose($conn);

            if ($this->events !== null) {
                $this->events->dispatch(new ConnectionPoisonedEvent($this->name, $poison ? 'caller' : 'closed-pool'));
                $this->events->dispatch(new ConnectionDestroyed($this->name));
            }

            return;
        }

        $accepted = $this->idle->push($conn);

        if (!$accepted) {
            $this->total--;
            $this->safeClose($conn);

            if ($this->events !== null) {
                $this->events->dispatch(new ConnectionDestroyed($this->name));
            }

            return;
        }

        $this->idleSince[spl_object_id($conn)] = hrtime(true);

        if ($this->events !== null) {
            $this->events->dispatch(new ConnectionReleased($this->name, Duration::nanos($heldNanos)));
        }
    }

    /**
     * @template T
     * @param Closure(Connection): T $fn
     * @return T
     */
    public function withConnection(Closure $fn): mixed
    {
        $conn = $this->take();
        $poison = false;

        try {
            return $fn($conn);
        } catch (Throwable $e) {
            $poison = true;

            throw $e;
        } finally {
            $this->release($conn, poison: $poison);
        }
    }

    /**
     * `$_timeout` is part of the public lifecycle contract; the current sync
     * drain is fast enough that we don't need to enforce the deadline yet,
     * but the param is kept so callers compile against a deadline-aware API.
     */
    public function close(Duration $_timeout): void
    {
        $this->closed = true;

        $drained = $this->idle->pop(Duration::zero());

        while ($drained !== null) {
            $this->total--;
            $this->safeClose($drained);

            if ($this->events !== null) {
                $this->events->dispatch(new ConnectionDestroyed($this->name));
            }

            $drained = $this->idle->pop(Duration::zero());
        }

        $this->idle->close();
    }

    public function stats(): PoolStats
    {
        return new PoolStats(
            idle: $this->idle->size(),
            inUse: $this->inUse->count(),
            total: $this->total,
            waitingCoroutines: $this->waitingCoroutines,
            totalBorrows: $this->totalBorrows,
            totalWaits: $this->totalWaits,
            totalTimeouts: $this->totalTimeouts,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function evictIdleOlderThan(int $cutoffNanos): void
    {
        $kept = [];

        while (($next = $this->idle->pop(Duration::zero())) !== null) {
            $id = spl_object_id($next);
            $stamp = $this->idleSince[$id] ?? 0;
            $age = $cutoffNanos - $stamp;

            if ($age >= $this->config->idleTtl->toNanos()) {
                $this->total--;
                $this->safeClose($next);
                unset($this->idleSince[$id]);
                $this->dispatchDestroyed();

                continue;
            }

            $kept[] = $next;
        }

        foreach ($kept as $conn) {
            $this->idle->push($conn);
        }
    }

    public function warnOnLeaks(int $nowNanos): void
    {
        foreach ($this->inUse as $conn) {
            $meta = $this->inUse[$conn];
            $ageNanos = $nowNanos - $meta['takenAt'];

            if ($ageNanos < $this->config->acquireTtl->toNanos()) {
                continue;
            }

            $this->logger->warning(
                sprintf(
                    'Connection borrow in pool "%s" held for %dms (acquireTtl exceeded)',
                    $this->name,
                    intdiv($ageNanos, 1_000_000),
                ),
            );
        }
    }

    public function warmToMinIdle(): void
    {
        while ($this->total < $this->config->minIdle) {
            $fresh = $this->factory->create();
            $this->total++;
            $this->idleSince[spl_object_id($fresh)] = hrtime(true);
            $this->dispatchCreated();

            if (!$this->idle->push($fresh)) {
                $this->total--;
                $this->safeClose($fresh);
                unset($this->idleSince[spl_object_id($fresh)]);
                $this->dispatchDestroyed();

                return;
            }
        }
    }

    private function markBorrowed(Connection $conn, int $startNanos): Connection
    {
        unset($this->idleSince[spl_object_id($conn)]);
        $meta = ['takenAt' => hrtime(true)];
        $this->inUse[$conn] = $meta;
        $this->totalBorrows++;

        if ($this->events !== null) {
            $waitNanos = hrtime(true) - $startNanos;
            $this->events->dispatch(new ConnectionTaken($this->name, Duration::nanos($waitNanos)));
        }

        return $conn;
    }

    private function waitForRelease(Duration $timeout, int $startNanos): Connection
    {
        $this->totalWaits++;
        $this->waitingCoroutines++;

        try {
            $waited = $this->idle->pop($timeout);
        } finally {
            $this->waitingCoroutines--;
        }

        if ($waited === null) {
            $this->totalTimeouts++;
            $stats = $this->stats();

            if ($this->events !== null) {
                $this->events->dispatch(new PoolExhaustedEvent($this->name, $stats));
            }

            throw PoolExhaustedException::after($this->name, $stats);
        }

        return $this->markBorrowed($waited, $startNanos);
    }

    private function dispatchCreated(): void
    {
        if ($this->events !== null) {
            $this->events->dispatch(new ConnectionCreated($this->name));
        }
    }

    private function dispatchDestroyed(): void
    {
        if ($this->events !== null) {
            $this->events->dispatch(new ConnectionDestroyed($this->name));
        }
    }

    private function safeClose(Connection $conn): void
    {
        try {
            $conn->close();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to close connection cleanly: {error}', ['error' => $e->getMessage()]);
        }
    }
}
