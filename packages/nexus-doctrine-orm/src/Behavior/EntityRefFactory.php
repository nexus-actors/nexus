<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Doctrine\Orm\Behavior\ReplayPolicy\EntityReplayPolicy;
use Monadial\Nexus\Doctrine\Orm\Pool\EntityManagerFactory;
use Monadial\Nexus\Runtime\Duration;
use Stringable;

/**
 * Spawns and caches one ActorRef per entity identity, enforcing a single writer per entity
 * within an ActorSystem. Subsequent calls to of() with the same id return the cached ref.
 *
 * @template T of object
 * @template C of object
 *
 * @psalm-api
 */
final class EntityRefFactory
{
    /** @var array<string, ActorRef> */
    private array $cache = [];

    /**
     * @param class-string<T>                                                              $entityClass
     * @param Closure(): \Doctrine\DBAL\Connection                                        $connectionSource
     * @param Closure(\Monadial\Nexus\Core\Actor\ActorContext<C>, C, T): EntityEffect<T>  $commandHandler
     * @param Closure(\Doctrine\DBAL\Connection): void|null                              $connectionRelease
     */
    private function __construct(
        private readonly ActorSpawner $spawner,
        private readonly string $entityClass,
        private readonly EntityManagerFactory $emFactory,
        private readonly Closure $connectionSource,
        private readonly Closure $commandHandler,
        private readonly EntityReplayPolicy $replayPolicy,
        private readonly ?Duration $receiveTimeout = null,
        private readonly ?Closure $connectionRelease = null,
    ) {}

    /**
     * @internal Constructed by EntityRefFactoryBuilder.
     * @psalm-suppress ArgumentTypeCoercion
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    public static function instantiate(
        ActorSpawner $spawner,
        string $entityClass,
        EntityManagerFactory $emFactory,
        Closure $connectionSource,
        Closure $commandHandler,
        EntityReplayPolicy $replayPolicy,
        ?Duration $receiveTimeout = null,
        ?Closure $connectionRelease = null,
    ): self {
        return new self(
            $spawner,
            $entityClass,
            $emFactory,
            $connectionSource,
            $commandHandler,
            $replayPolicy,
            $receiveTimeout,
            $connectionRelease,
        );
    }

    /**
     * Return the ActorRef for the given entity id, spawning it on first call.
     */
    public function of(mixed $id): ActorRef
    {
        $name = self::deriveName($this->entityClass, $id);

        if (isset($this->cache[$name]) && $this->cache[$name]->isAlive()) {
            return $this->cache[$name];
        }

        unset($this->cache[$name]);

        $behaviorBuilder = EntityBehavior::create($this->entityClass, $id, $this->commandHandler)
            ->withEntityManagerFactory($this->emFactory)
            ->withReplayPolicy($this->replayPolicy);

        $behaviorBuilder = $this->connectionRelease !== null
            ? $behaviorBuilder->withConnectionLifecycle($this->connectionSource, $this->connectionRelease)
            : $behaviorBuilder->withConnectionSource($this->connectionSource);

        if ($this->receiveTimeout !== null) {
            $behaviorBuilder = $behaviorBuilder->withReceiveTimeout($this->receiveTimeout);
        }

        return $this->cache[$name] = $this->spawner->spawn(Props::fromBehavior($behaviorBuilder->toBehavior()), $name);
    }

    /**
     * Entry point for the fluent builder.
     *
     * @template U of object
     * @param class-string<U> $entityClass
     * @return EntityRefFactoryBuilder<U, object>
     */
    public static function for(ActorSpawner $spawner, string $entityClass): EntityRefFactoryBuilder
    {
        return new EntityRefFactoryBuilder($spawner, $entityClass);
    }

    /**
     * Derive the deterministic actor name for a given entity class and id.
     * Namespace separators are replaced with dots so the name is path-safe.
     * The separator '--' satisfies ActorPath's NAME_PATTERN ([a-zA-Z0-9_\-\.]+).
     *
     * Accepts scalar ids and any object implementing `__toString()`. Other
     * objects (e.g. Symfony's `Uuid`, which is Stringable) work; raw
     * non-Stringable objects throw because there is no path-safe
     * representation we can derive automatically.
     */
    public static function deriveName(string $entityClass, mixed $id): string
    {
        if (is_object($id) && !$id instanceof Stringable) {
            throw new InvalidArgumentException(
                sprintf(
                    'Entity id of type %s is not Stringable; pass a scalar or Stringable id, '
                    . 'or use EntityRefFactory::of() with a pre-stringified key',
                    $id::class,
                ),
            );
        }

        if (!is_scalar($id) && !$id instanceof Stringable) {
            throw new InvalidArgumentException(
                sprintf('Entity id of type %s cannot be derived into an actor name', get_debug_type($id)),
            );
        }

        return str_replace('\\', '.', $entityClass) . '--' . (string) $id;
    }
}
