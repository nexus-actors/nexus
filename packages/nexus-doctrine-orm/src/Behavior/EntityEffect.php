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

    public static function same(): self
    {
        return new self(EntityEffectKind::Same);
    }

    public static function persist(): self
    {
        return new self(EntityEffectKind::Persist);
    }

    public static function remove(): self
    {
        return new self(EntityEffectKind::Remove);
    }

    public static function stop(): self
    {
        return new self(EntityEffectKind::Stop);
    }

    public static function stash(): self
    {
        return new self(EntityEffectKind::Stash);
    }
}
