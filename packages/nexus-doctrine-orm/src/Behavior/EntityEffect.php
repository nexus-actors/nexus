<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;
use Throwable;

/**
 * @template T of object
 *
 * @psalm-api
 */
final readonly class EntityEffect
{
    /**
     * @param list<Closure(T): void>                                         $runHooks
     * @param list<array{ref: ActorRef, build: Closure(T): object}>          $replyHooks
     * @param list<array{ref: ActorRef, build: Closure(Throwable): object}>  $failureHooks
     *        Fired by the runner when the flush (or remove) fails — either an
     *        optimistic-lock conflict or any other infra error — so the caller
     *        gets a reply instead of hanging on its ask timeout.
     */
    private function __construct(
        public EntityEffectKind $kind,
        public ?ActorRef $immediateReplyRef = null,
        public ?object $immediateReplyMessage = null,
        public array $runHooks = [],
        public array $replyHooks = [],
        public array $failureHooks = [],
    ) {}

    /**
     * @template U of object
     * @return EntityEffect<U>
     */
    public static function same(): self
    {
        /** @var EntityEffect<U> */
        return new self(EntityEffectKind::Same);
    }

    /**
     * @template U of object
     * @return EntityEffect<U>
     */
    public static function persist(): self
    {
        /** @var EntityEffect<U> */
        return new self(EntityEffectKind::Persist);
    }

    /**
     * @template U of object
     * @return EntityEffect<U>
     */
    public static function remove(): self
    {
        /** @var EntityEffect<U> */
        return new self(EntityEffectKind::Remove);
    }

    /**
     * @template U of object
     * @return EntityEffect<U>
     */
    public static function stop(): self
    {
        /** @var EntityEffect<U> */
        return new self(EntityEffectKind::Stop);
    }

    /**
     * @template U of object
     * @return EntityEffect<U>
     */
    public static function stash(): self
    {
        /** @var EntityEffect<U> */
        return new self(EntityEffectKind::Stash);
    }

    public static function reply(ActorRef $to, object $message): self
    {
        return new self(EntityEffectKind::Same, immediateReplyRef: $to, immediateReplyMessage: $message);
    }

    /**
     * @param Closure(T): void $hook
     */
    public function thenRun(Closure $hook): self
    {
        return new self(
            kind: $this->kind,
            immediateReplyRef: $this->immediateReplyRef,
            immediateReplyMessage: $this->immediateReplyMessage,
            runHooks: [...$this->runHooks, $hook],
            replyHooks: $this->replyHooks,
            failureHooks: $this->failureHooks,
        );
    }

    /**
     * @param Closure(T): object $build
     */
    public function thenReply(ActorRef $to, Closure $build): self
    {
        return new self(
            kind: $this->kind,
            immediateReplyRef: $this->immediateReplyRef,
            immediateReplyMessage: $this->immediateReplyMessage,
            runHooks: $this->runHooks,
            replyHooks: [...$this->replyHooks, ['ref' => $to, 'build' => $build]],
            failureHooks: $this->failureHooks,
        );
    }

    /**
     * Register a reply to fire when the flush (or remove) fails. The runner
     * passes the caught throwable to `$build` so the caller learns the command
     * failed instead of silently waiting out its ask timeout.
     *
     * @param Closure(Throwable): object $build
     */
    public function thenReplyOnFailure(ActorRef $to, Closure $build): self
    {
        return new self(
            kind: $this->kind,
            immediateReplyRef: $this->immediateReplyRef,
            immediateReplyMessage: $this->immediateReplyMessage,
            runHooks: $this->runHooks,
            replyHooks: $this->replyHooks,
            failureHooks: [...$this->failureHooks, ['ref' => $to, 'build' => $build]],
        );
    }
}
