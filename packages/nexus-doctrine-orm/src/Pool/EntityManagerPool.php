<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Pool;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolClosedException;
use Monadial\Nexus\Doctrine\Dbal\Exception\PoolExhaustedException;
use Monadial\Nexus\Doctrine\Dbal\Pool\Channel\Channel;
use Monadial\Nexus\Doctrine\Dbal\Pool\ConnectionPool;
use Monadial\Nexus\Doctrine\Dbal\Pool\PoolStats;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCleared;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerCreated;
use Monadial\Nexus\Doctrine\Orm\Event\EntityManagerEvicted;
use Monadial\Nexus\Runtime\Duration;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SplObjectStorage;
use Throwable;

/**
 * @psalm-api
 *
 * @psalm-type EmMeta = array{conn: Connection}
 */
final class EntityManagerPool
{
    /** @var Channel<PooledEntityManager> */
    private Channel $idle;

    /** @var SplObjectStorage<PooledEntityManager, EmMeta> */
    private SplObjectStorage $live;

    private int $total = 0;
    private int $inUse = 0;
    private int $totalBorrows = 0;
    private int $totalEvictions = 0;
    private int $totalWaits = 0;
    private int $totalTimeouts = 0;
    private int $waitingCoroutines = 0;
    private bool $closed = false;

    /**
     * @param Channel<PooledEntityManager> $channel
     */
    public function __construct(
        private readonly string $name,
        private readonly EntityManagerFactory $factory,
        private readonly ConnectionPool $connPool,
        private readonly EmPoolConfig $config,
        Channel $channel,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->idle = $channel;
        /** @var SplObjectStorage<PooledEntityManager, EmMeta> $live */
        $live = new SplObjectStorage();
        $this->live = $live;
    }

    public function take(?Duration $timeout = null): PooledEntityManager
    {
        if ($this->closed) {
            throw new PoolClosedException($this->name);
        }

        $existing = $this->idle->pop(Duration::zero());

        if ($existing !== null) {
            return $this->lendFromIdle($existing);
        }

        if ($this->total < $this->config->max) {
            $this->total++;

            try {
                return $this->createAndBorrow();
            } catch (Throwable $e) {
                $this->total--;

                throw $e;
            }
        }

        $this->totalWaits++;
        $this->waitingCoroutines++;

        try {
            $waited = $this->idle->pop($timeout ?? $this->config->borrowTimeout);
        } finally {
            $this->waitingCoroutines--;
        }

        if ($waited === null) {
            $this->totalTimeouts++;

            throw PoolExhaustedException::after(
                $this->name,
                new PoolStats(
                    idle: $this->idle->size(),
                    inUse: $this->inUse,
                    total: $this->total,
                    waitingCoroutines: $this->waitingCoroutines,
                    totalBorrows: $this->totalBorrows,
                    totalWaits: $this->totalWaits,
                    totalTimeouts: $this->totalTimeouts,
                ),
            );
        }

        return $this->lendFromIdle($waited);
    }

    public function release(PooledEntityManager $em): void
    {
        if (!$this->live->offsetExists($em)) {
            return;
        }

        $this->inUse--;

        if ($this->closed) {
            $this->destroy($em, 'closed-pool');

            return;
        }

        if (!$em->isOpen()) {
            $this->destroy($em, 'em-closed');

            return;
        }

        if ($this->config->recreateAfter > 0 && $em->borrowCount() >= $this->config->recreateAfter) {
            $this->destroy($em, 'recreate-after');

            return;
        }

        // Roll back any transaction left open on the EM's underlying connection
        // before the EM is reused: the ORM shares that connection, so a later
        // borrow (possibly a different tenant/request) must not inherit an open
        // transaction. A rollback that fails poisons the EM rather than
        // requeueing dirty state. The identity map is cleared on the next
        // lendFromIdle() when clearOnReturn is enabled.
        if (!$this->rollbackActiveTransaction($em)) {
            $this->destroy($em, 'cleanup-failed');

            return;
        }

        $accepted = $this->idle->push($em);

        if (!$accepted) {
            $this->destroy($em, 'channel-full');
        }
    }

    /**
     * @template T
     * @param Closure(EntityManagerInterface): T $fn
     * @return T
     */
    public function withEntityManager(Closure $fn): mixed
    {
        $em = $this->take();

        try {
            return $fn($em);
        } finally {
            $this->release($em);
        }
    }

    /**
     * `$_timeout` is kept for API symmetry with ConnectionPool::close();
     * the current drain is sync, no deadline needed yet.
     */
    public function close(Duration $_timeout): void
    {
        $this->closed = true;
        $drained = $this->idle->pop(Duration::zero());

        while ($drained !== null) {
            $this->destroy($drained, 'closed-pool');
            $drained = $this->idle->pop(Duration::zero());
        }

        $this->idle->close();
    }

    public function stats(): EmPoolStats
    {
        return new EmPoolStats(
            idle: $this->idle->size(),
            inUse: $this->inUse,
            total: $this->total,
            totalBorrows: $this->totalBorrows,
            totalEvictions: $this->totalEvictions,
            waitingCoroutines: $this->waitingCoroutines,
            totalWaits: $this->totalWaits,
            totalTimeouts: $this->totalTimeouts,
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    private function rollbackActiveTransaction(PooledEntityManager $em): bool
    {
        try {
            $connection = $em->getConnection();

            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->warning(
                'Failed to roll back EM connection on release; poisoning it: {error}',
                ['error' => $e->getMessage()],
            );

            return false;
        }
    }

    private function createAndBorrow(): PooledEntityManager
    {
        $conn = $this->connPool->take();

        try {
            $inner = $this->factory->create($conn);
        } catch (Throwable $e) {
            $this->connPool->release($conn, poison: true);

            throw $e;
        }

        $em = new PooledEntityManager($inner);
        $meta = ['conn' => $conn];
        $this->live[$em] = $meta;
        $this->dispatchCreated();

        return $this->markBorrowed($em);
    }

    private function lendFromIdle(PooledEntityManager $em): PooledEntityManager
    {
        if ($this->config->clearOnReturn) {
            $em->clear();
            $this->dispatchCleared();
        }

        return $this->markBorrowed($em);
    }

    private function markBorrowed(PooledEntityManager $em): PooledEntityManager
    {
        $em->markBorrowed();
        $this->inUse++;
        $this->totalBorrows++;

        return $em;
    }

    private function destroy(PooledEntityManager $em, string $reason): void
    {
        $meta = $this->live->offsetExists($em)
            ? $this->live[$em]
            : null;
        $this->live->offsetUnset($em);
        $this->total--;
        $this->totalEvictions++;

        try {
            $em->close();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to close EM cleanly: {error}', ['error' => $e->getMessage()]);
        }

        if ($meta !== null) {
            $this->connPool->release($meta['conn']);
        }

        $this->dispatchEvicted($reason);
    }

    private function dispatchCreated(): void
    {
        if ($this->events !== null) {
            $this->events->dispatch(new EntityManagerCreated($this->name));
        }
    }

    private function dispatchCleared(): void
    {
        if ($this->events !== null) {
            $this->events->dispatch(new EntityManagerCleared($this->name));
        }
    }

    private function dispatchEvicted(string $reason): void
    {
        if ($this->events !== null) {
            $this->events->dispatch(new EntityManagerEvicted($this->name, $reason));
        }
    }
}
