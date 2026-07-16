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
use Monadial\Nexus\Core\Lifecycle\ReceiveTimeout;
use Monadial\Nexus\Doctrine\Orm\Exception\EntityConflictException;
use RuntimeException;
use Throwable;

/**
 * @psalm-internal Monadial\Nexus\Doctrine\Orm
 */
final class EntityBehaviorRunner
{
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
        $connectionRelease = $builder->connectionRelease;

        return Behavior::setup(
            /**
             * @param ActorContext<object> $_ctx
             * @return Behavior<object>
             */
            static function (ActorContext $_ctx) use ($builder, $emFactory, $connectionSource, $connectionRelease): Behavior {
                $connection = ($connectionSource)();
                $em = $emFactory->create($connection);
                $entity = $builder->replayPolicy->resolve($em, $builder->entityClass, $builder->id);

                if ($builder->receiveTimeout !== null) {
                    $_ctx->setReceiveTimeout($builder->receiveTimeout);
                }

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
                            // Concurrent write lost the version race. Notify the
                            // failure-reply targets with the domain conflict, then
                            // stop: the caller learns the write failed instead of
                            // hanging on its ask timeout, and the actor tears down
                            // its now-suspect EM/Connection via PostStop so of()
                            // prunes the dead ref and respawns a fresh actor.
                            self::fireFailureHooks(
                                $effect,
                                new EntityConflictException($builder->entityClass, $builder->id, $e),
                            );

                            return BehaviorWithState::stopped();
                        } catch (Throwable $e) {
                            // Any other infra failure (dead connection, driver
                            // error, ...): reply to the failure targets so the
                            // caller does not hang, then stop instead of lingering
                            // as a zombie holding a stale EntityManager / dead
                            // Connection. of() respawns on the next message.
                            self::fireFailureHooks($effect, $e);

                            return BehaviorWithState::stopped();
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
                    static function (ActorContext $innerCtx, object $signal) use ($em, $connection, $connectionRelease): Behavior {
                        if ($signal instanceof ReceiveTimeout) {
                            return Behavior::stopped();
                        }

                        if ($signal instanceof PostStop) {
                            // EM goes first either way — closing it lets any
                            // open transaction roll back on the connection
                            // before we hand the connection back.
                            $em->close();

                            if ($connectionRelease !== null) {
                                // Pool-backed: return the slot, do not close.
                                ($connectionRelease)($connection);
                            } else {
                                // Dedicated: we owned this connection.
                                $connection->close();
                            }
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

    private static function fireFailureHooks(EntityEffect $effect, Throwable $cause): void
    {
        foreach ($effect->failureHooks as $failure) {
            $failure['ref']->tell(($failure['build'])($cause));
        }
    }
}
