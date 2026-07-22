<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor;

use InvalidArgumentException;
use Monadial\Nexus\Core\Event\MessageDeadLettered;
use Monadial\Nexus\Core\Exception\AskTimeoutException;
use Monadial\Nexus\Runtime\Async\Future;
use Monadial\Nexus\Runtime\Duration;
use NoDiscard;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_shift;
use function count;
use function sprintf;

/**
 * @psalm-api
 *
 * Null-object ActorRef that captures dead letters.
 *
 * Every message sent via tell() increments a monotonic counter, is dispatched as a
 * MessageDeadLettered event (when an EventDispatcher is configured), and is retained
 * in a bounded ring buffer of the most recent samples for inspection. The buffer is
 * capped at $maxSamples (default 1000): once full, the oldest sample is evicted so
 * memory stays stable no matter how many messages are dead-lettered. The counter
 * (see total()) keeps counting past the cap, so it is a truthful delivery-failure
 * signal even though captured() only shows the recent tail.
 *
 * ask() immediately throws AskTimeoutException.
 * isAlive() always returns false.
 *
 * @internal Implementation detail of {@see ActorSystem::deadLetters()}. Not for direct use.
 *
 * @implements ActorRef<object>
 */
final class DeadLetterRef implements ActorRef
{
    private ActorPath $path;

    /** @var list<object> bounded ring buffer of the most recent dead-lettered messages */
    private array $captured = [];

    private int $total = 0;

    private readonly EventDispatcherInterface $events;

    /**
     * @param int $maxSamples maximum number of recent messages retained for inspection
     */
    public function __construct(private readonly int $maxSamples = 1000, ?EventDispatcherInterface $events = null)
    {
        if ($maxSamples < 1) {
            throw new InvalidArgumentException(
                sprintf('maxSamples must be a positive integer, got %d.', $maxSamples),
            );
        }

        $this->path = ActorPath::fromString('/system/deadLetters');
        $this->events = $events ?? new NullDispatcher();
    }

    #[Override]
    public function tell(object $message): void
    {
        $this->total++;
        $this->captured[] = $message;

        if (count($this->captured) > $this->maxSamples) {
            array_shift($this->captured);
        }

        $this->events->dispatch(new MessageDeadLettered($message));
    }

    /**
     * @template R of object
     * @return Future<R>
     * @throws AskTimeoutException
     */
    #[Override]
    #[NoDiscard]
    public function ask(object $message, Duration $timeout): Future
    {
        throw new AskTimeoutException($this->path, $timeout);
    }

    #[Override]
    public function path(): ActorPath
    {
        return $this->path;
    }

    #[Override]
    public function isAlive(): bool
    {
        return false;
    }

    /**
     * The most recent dead-lettered messages, bounded by $maxSamples.
     *
     * @return list<object>
     */
    public function captured(): array
    {
        return $this->captured;
    }

    /**
     * Total number of messages ever dead-lettered — monotonic, never reset, and not
     * bounded by $maxSamples. Use this as the delivery-failure metric; captured() is
     * only a recent-sample window.
     */
    public function total(): int
    {
        return $this->total;
    }
}
