<?php

declare(strict_types=1);

namespace Monadial\Nexus\Doctrine\Orm\Behavior;

use Closure;
use Monadial\Nexus\Core\Actor\ActorRef;

/**
 * @template T of object
 *
 * @psalm-api
 */
final readonly class EntityEffect
{
    /**
     * @param list<Closure(T): void>                                 $runHooks
     * @param list<array{ref: ActorRef, build: Closure(T): object}>  $replyHooks
     */
    private function __construct(
        public EntityEffectKind $kind,
        public ?ActorRef $immediateReplyRef = null,
        public ?object $immediateReplyMessage = null,
        public array $runHooks = [],
        public array $replyHooks = [],
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
        );
    }
}
