<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\LockMode;
use LogicException;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\EntityReplayPolicy;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;

/**
 * @template T of object
 * @template C of object
 *
 * @psalm-api
 */
final readonly class EntityBehaviorBuilder
{
    public EntityReplayPolicy $replayPolicy;

    /**
     * @param class-string<T> $entityClass
     * @param Closure(\Monadial\Nexus\Core\Actor\ActorContext<C>, C, T): EntityEffect<T> $commandHandler
     * @param Closure(): \Doctrine\DBAL\Connection|null $connectionSource
     * @param Closure(\Doctrine\DBAL\Connection): void|null $connectionRelease
     *        Optional release hook invoked on PostStop. When null the runner
     *        falls back to `$connection->close()` (dedicated-connection mode).
     *        Pass a release callback when the connection comes from an
     *        external pool, so the slot is returned instead of permanently
     *        consumed.
     */
    public function __construct(
        public string $entityClass,
        public mixed $id,
        public Closure $commandHandler,
        public ?EntityManagerFactory $emFactory = null,
        ?EntityReplayPolicy $replayPolicy = null,
        public ?LockMode $lockMode = null,
        public ?Closure $connectionSource = null,
        public ?Duration $receiveTimeout = null,
        public ?Closure $connectionRelease = null,
    ) {
        $this->replayPolicy = $replayPolicy ?? new FailIfMissing();
    }

    public function withEntityManagerFactory(EntityManagerFactory $factory): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $factory,
            replayPolicy: $this->replayPolicy,
            lockMode: $this->lockMode,
            connectionSource: $this->connectionSource,
            receiveTimeout: $this->receiveTimeout,
            connectionRelease: $this->connectionRelease,
        );
    }

    public function withReplayPolicy(EntityReplayPolicy $policy): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $this->emFactory,
            replayPolicy: $policy,
            lockMode: $this->lockMode,
            connectionSource: $this->connectionSource,
            receiveTimeout: $this->receiveTimeout,
            connectionRelease: $this->connectionRelease,
        );
    }

    public function withLockMode(LockMode $mode): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $this->emFactory,
            replayPolicy: $this->replayPolicy,
            lockMode: $mode,
            connectionSource: $this->connectionSource,
            receiveTimeout: $this->receiveTimeout,
            connectionRelease: $this->connectionRelease,
        );
    }

    /**
     * Dedicated-connection mode: the runner takes ownership of the connection
     * returned by `$source` and `close()`s it on PostStop. Use this when the
     * source creates a fresh connection (e.g. `DriverManager::getConnection`).
     *
     * For pool-backed connections use {@see self::withConnectionLifecycle()}
     * so the slot is released instead of closed.
     *
     * @param Closure(): \Doctrine\DBAL\Connection $source
     */
    public function withConnectionSource(Closure $source): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $this->emFactory,
            replayPolicy: $this->replayPolicy,
            lockMode: $this->lockMode,
            connectionSource: $source,
            receiveTimeout: $this->receiveTimeout,
            connectionRelease: null,
        );
    }

    /**
     * Pool-backed mode: the runner acquires via `$acquire`, hands off, and
     * calls `$release($conn)` on PostStop instead of `close()`. Use this when
     * the connection comes from a `ConnectionPool` so the slot is returned
     * to the pool when the actor passivates.
     *
     * @param Closure(): \Doctrine\DBAL\Connection $acquire
     * @param Closure(\Doctrine\DBAL\Connection): void $release
     */
    public function withConnectionLifecycle(Closure $acquire, Closure $release): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $this->emFactory,
            replayPolicy: $this->replayPolicy,
            lockMode: $this->lockMode,
            connectionSource: $acquire,
            receiveTimeout: $this->receiveTimeout,
            connectionRelease: $release,
        );
    }

    public function withReceiveTimeout(Duration $timeout): self
    {
        return new self(
            entityClass: $this->entityClass,
            id: $this->id,
            commandHandler: $this->commandHandler,
            emFactory: $this->emFactory,
            replayPolicy: $this->replayPolicy,
            lockMode: $this->lockMode,
            connectionSource: $this->connectionSource,
            receiveTimeout: $timeout,
            connectionRelease: $this->connectionRelease,
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @psalm-suppress ArgumentTypeCoercion
     */
    public function withDirectConnection(array $params): self
    {
        return $this->withConnectionSource(static fn() => DriverManager::getConnection($params));
    }

    public function toBehavior(): Behavior
    {
        if ($this->emFactory === null) {
            throw new LogicException(
                'EntityManagerFactory required — call withEntityManagerFactory() before toBehavior()',
            );
        }

        if ($this->connectionSource === null) {
            throw new LogicException(
                'Connection source required — call withConnectionSource() or withDirectConnection()',
            );
        }

        return EntityBehaviorRunner::build($this);
    }
}
