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
    ) {
    }

    public static function default(): self
    {
        return new self(Duration::millis(100), UnroutablePolicy::Reject);
    }

    public function withPollInterval(Duration $pollInterval): self
    {
        return new self($pollInterval, $this->unroutablePolicy);
    }

    public function withUnroutablePolicy(UnroutablePolicy $unroutablePolicy): self
    {
        return new self($this->pollInterval, $unroutablePolicy);
    }
}
