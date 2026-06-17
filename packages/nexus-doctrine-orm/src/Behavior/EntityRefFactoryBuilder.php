<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use LogicException;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\EntityReplayPolicy;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\FailIfMissing;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;

/** @psalm-api */
final class EntityRefFactoryBuilder
{
    private EntityReplayPolicy $replayPolicy;
    private ?Duration $receiveTimeout = null;
    private ?EntityManagerFactory $emFactory = null;
    private ?Closure $connectionSource = null;
    private ?Closure $commandHandler = null;

    public function __construct(private readonly ActorSpawner $spawner, private readonly string $entityClass,) {
        $this->replayPolicy = new FailIfMissing();
    }

    public function using(EntityManagerFactory $factory): self
    {
        $this->emFactory = $factory;

        return $this;
    }

    public function withConnectionSource(Closure $source): self
    {
        $this->connectionSource = $source;

        return $this;
    }

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
        );
    }
}
