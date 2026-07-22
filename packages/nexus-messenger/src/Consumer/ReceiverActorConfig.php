<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

use InvalidArgumentException;
use Monadial\Nexus\Runtime\Duration;

use function sprintf;

/**
 * Tuning knobs for a ReceiverActor poll loop.
 *
 * @psalm-api
 */
final readonly class ReceiverActorConfig
{
    private function __construct(
        public Duration $pollInterval,
        public UnroutablePolicy $unroutablePolicy,
        public Duration $askPendingTimeout,
        public int $maxPendingAsks,
    ) {
        if ($maxPendingAsks < 1) {
            throw new InvalidArgumentException(
                sprintf('maxPendingAsks must be a positive integer, got %d.', $maxPendingAsks),
            );
        }
    }

    public static function default(): self
    {
        return new self(Duration::millis(100), UnroutablePolicy::Reject, Duration::seconds(30), 1024);
    }

    public function withPollInterval(Duration $pollInterval): self
    {
        return new self($pollInterval, $this->unroutablePolicy, $this->askPendingTimeout, $this->maxPendingAsks);
    }

    public function withUnroutablePolicy(UnroutablePolicy $unroutablePolicy): self
    {
        return new self($this->pollInterval, $unroutablePolicy, $this->askPendingTimeout, $this->maxPendingAsks);
    }

    public function withAskPendingTimeout(Duration $askPendingTimeout): self
    {
        return new self($this->pollInterval, $this->unroutablePolicy, $askPendingTimeout, $this->maxPendingAsks);
    }

    /**
     * Cap on the number of unanswered ask envelopes held in memory at once.
     *
     * Once the cap is reached a new ask is shed — rejected for broker
     * redelivery instead of being tracked — so a producer flooding ask
     * envelopes cannot grow the responder's pending map without bound.
     */
    public function withMaxPendingAsks(int $maxPendingAsks): self
    {
        return new self($this->pollInterval, $this->unroutablePolicy, $this->askPendingTimeout, $maxPendingAsks);
    }
}
