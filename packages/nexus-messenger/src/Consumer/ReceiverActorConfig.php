<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Consumer;

use Monadial\Nexus\Runtime\Duration;

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
    ) {
    }

    public static function default(): self
    {
        return new self(Duration::millis(100), UnroutablePolicy::Reject, Duration::seconds(30));
    }

    public function withPollInterval(Duration $pollInterval): self
    {
        return new self($pollInterval, $this->unroutablePolicy, $this->askPendingTimeout);
    }

    public function withUnroutablePolicy(UnroutablePolicy $unroutablePolicy): self
    {
        return new self($this->pollInterval, $unroutablePolicy, $this->askPendingTimeout);
    }

    public function withAskPendingTimeout(Duration $askPendingTimeout): self
    {
        return new self($this->pollInterval, $this->unroutablePolicy, $askPendingTimeout);
    }
}
