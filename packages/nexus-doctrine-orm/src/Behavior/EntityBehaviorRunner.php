<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use LogicException;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\Behavior;
use Monadial\Nexus\Core\Actor\BehaviorWithState;
use Monadial\Nexus\Core\Lifecycle\PostStop;
use Monadial\Nexus\Doctrine\Orm\Exception\EntityConflictException;
use RuntimeException;

/**
 * @psalm-internal Monadial\Nexus\Doctrine\Orm
 */
final class EntityBehaviorRunner
{
    /**
     * @psalm-suppress InvalidArgument Psalm cannot infer generic U/S for Behavior::setup + withState from runtime types
     * @psalm-suppress MixedArgumentTypeCoercion EntityReplayPolicy::resolve returns mixed|object at call site
     * @psalm-suppress UnusedClosureParam ActorContext injected by ActorCell; not needed in setup closure
     */
    public static function build(EntityBehaviorBuilder $builder): Behavior
    {
        if ($builder->emFactory === null) {
            throw new LogicException('EntityManagerFactory required');
        }

        if ($builder->connectionSource === null) {
            throw new LogicException('Connection source required');
        }

        $emFactory = $builder->emFactory;
        $connectionSource = $builder->connectionSource;

        return Behavior::setup(
            static function (ActorContext $_ctx) use ($builder, $emFactory, $connectionSource): Behavior {
                $connection = ($connectionSource)();
                $em = $emFactory->create($connection);
                $entity = $builder->replayPolicy->resolve($em, $builder->entityClass, $builder->id);

                return Behavior::withState(
                    $entity,
                    static function (
                        ActorContext $innerCtx,
                        object $msg,
                        ?object $state,
                    ) use ($builder, $em): BehaviorWithState {
                        if ($state === null) {
                            $resolved = $em->find($builder->entityClass, $builder->id);

                            if ($resolved === null) {
                                throw new RuntimeException(
                                    sprintf(
                                        'Entity %s::%s not found on deferred load',
                                        $builder->entityClass,
                                        (string) $builder->id,
                                    ),
                                );
                            }

                            $state = $resolved;
                        }

                        /** @var EntityEffect $effect */
                        $effect = ($builder->commandHandler)($innerCtx, $msg, $state);

                        if ($effect->immediateReplyRef !== null && $effect->immediateReplyMessage !== null) {
                            $effect->immediateReplyRef->tell($effect->immediateReplyMessage);
                        }

                        try {
                            match ($effect->kind) {
                                EntityEffectKind::Same    => null,
                                EntityEffectKind::Persist => $em->flush(),
                                EntityEffectKind::Remove  => self::removeAndFlush($em, $state),
                                EntityEffectKind::Stop    => null,
                                EntityEffectKind::Stash   => $innerCtx->stash(),
                            };
                        } catch (OptimisticLockException $e) {
                            throw new EntityConflictException($builder->entityClass, $builder->id, $e);
                        }

                        if ($effect->kind !== EntityEffectKind::Stop && $effect->kind !== EntityEffectKind::Remove) {
                            foreach ($effect->runHooks as $hook) {
                                $hook($state);
                            }

                            foreach ($effect->replyHooks as $reply) {
                                $reply['ref']->tell(($reply['build'])($state));
                            }
                        }

                        return match ($effect->kind) {
                            EntityEffectKind::Stop,
                            EntityEffectKind::Remove => BehaviorWithState::stopped(),
                            EntityEffectKind::Stash  => BehaviorWithState::same(),
                            default                  => BehaviorWithState::next($state),
                        };
                    },
                )->onSignal(
                    static function (ActorContext $innerCtx, object $signal) use ($em, $connection): Behavior {
                        if ($signal instanceof PostStop) {
                            $em->close();
                            $connection->close();
                        }

                        return Behavior::same();
                    },
                );
            },
        );
    }

    private static function removeAndFlush(EntityManagerInterface $em, object $entity): void
    {
        $em->remove($entity);
        $em->flush();
    }
}
