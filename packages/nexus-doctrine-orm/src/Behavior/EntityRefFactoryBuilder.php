<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use LogicException;
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
final class EntityRefFactoryBuilder
{
    /** @var EntityReplayPolicy<T> */
    private EntityReplayPolicy $replayPolicy;
    private ?Duration $receiveTimeout = null;
    private ?EntityManagerFactory $emFactory = null;
    private ?Closure $connectionSource = null;
    private ?Closure $connectionRelease = null;
    private ?Closure $commandHandler = null;

    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(private readonly ActorSpawner $spawner, private readonly string $entityClass)
    {
        /** @var EntityReplayPolicy<T> */
        $this->replayPolicy = new FailIfMissing();
    }

    public function using(EntityManagerFactory $factory): self
    {
        $this->emFactory = $factory;

        return $this;
    }

    /**
     * Dedicated-connection mode: each spawned actor owns the connection
     * returned by `$source` and `close()`s it on PostStop. Use for fresh
     * connections (e.g. `DriverManager::getConnection`).
     */
    public function withConnectionSource(Closure $source): self
    {
        $this->connectionSource = $source;
        $this->connectionRelease = null;

        return $this;
    }

    /**
     * Pool-backed mode: the actor borrows via `$acquire` and the pool slot
     * is returned via `$release($conn)` on PostStop instead of `close()`.
     *
     * @param Closure(): \Doctrine\DBAL\Connection $acquire
     * @param Closure(\Doctrine\DBAL\Connection): void $release
     */
    public function withConnectionLifecycle(Closure $acquire, Closure $release): self
    {
        $this->connectionSource = $acquire;
        $this->connectionRelease = $release;

        return $this;
    }

    /**
     * @param EntityReplayPolicy<T> $policy
     */
    public function withReplayPolicy(EntityReplayPolicy $policy): self
    {
        $this->replayPolicy = $policy;

        return $this;
    }

    public function withReceiveTimeout(Duration $timeout): self
    {
        $this->receiveTimeout = $timeout;

        return $this;
    }

    public function handle(Closure $commandHandler): self
    {
        $this->commandHandler = $commandHandler;

        return $this;
    }

    /**
     * @psalm-suppress ArgumentTypeCoercion,MixedArgumentTypeCoercion
     */
    public function build(): EntityRefFactory
    {
        if ($this->emFactory === null || $this->connectionSource === null || $this->commandHandler === null) {
            throw new LogicException(
                'EntityRefFactoryBuilder: using(), withConnectionSource(), and handle() are all required',
            );
        }

        return EntityRefFactory::instantiate(
            $this->spawner,
            $this->entityClass,
            $this->emFactory,
            $this->connectionSource,
            $this->commandHandler,
            $this->replayPolicy,
            $this->receiveTimeout,
            $this->connectionRelease,
        );
    }
}
