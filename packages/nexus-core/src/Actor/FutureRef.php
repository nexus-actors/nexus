<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use Monadial\Nexus\Core\Duration;
use NoDiscard;
use Override;
use RuntimeException;

/**
 * @psalm-api
 *
 * Lightweight ActorRef whose tell() resolves a FutureSlot.
 *
 * Created internally by ask(). Not intended for direct use.
 *
 * @template T of object
 * @implements ActorRef<T>
 */
final readonly class FutureRef implements ActorRef
{
    public function __construct(private ActorPath $path, private FutureSlot $slot) {}

    /** @param T $message */
    #[Override]
    public function tell(object $message): void
    {
        $this->slot->resolve($message);
    }

    /**
     * @return Future<object>
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new RuntimeException('Cannot ask() a FutureRef');
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->path;
    }

    #[Override]
    public function isAlive(): bool
    {
        return !$this->slot->isResolved();
    }
}
