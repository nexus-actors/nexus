<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Messenger\Support;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

use function array_values;
use function spl_object_id;

/**
 * A batch receiver double that models a broker delivering many un-acked messages at
 * once (prefetch) — unlike Symfony's InMemoryTransport, which hands over one un-acked
 * message at a time and so cannot flood a consumer's pending map.
 *
 * `get()` returns every currently in-flight envelope on each call (redelivering
 * un-settled ones). `ack()` settles an envelope permanently. `reject()` either drops
 * the envelope or requeues it for redelivery, depending on `$redeliverRejected`.
 */
final class FloodReceiver implements ReceiverInterface
{
    /** @var list<Envelope> */
    public array $acked = [];

    /** @var list<Envelope> */
    public array $rejected = [];

    /** @var array<int, Envelope> insertion-ordered in-flight envelopes */
    private array $inflight = [];

    public function __construct(private readonly bool $redeliverRejected = true) {}

    public function push(Envelope $envelope): void
    {
        $this->inflight[spl_object_id($envelope)] = $envelope;
    }

    /**
     * @return iterable<Envelope>
     */
    #[Override]
    public function get(): iterable
    {
        return array_values($this->inflight);
    }

    #[Override]
    public function ack(Envelope $envelope): void
    {
        unset($this->inflight[spl_object_id($envelope)]);
        $this->acked[] = $envelope;
    }

    #[Override]
    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = $envelope;

        if ($this->redeliverRejected) {
            return;
        }

        unset($this->inflight[spl_object_id($envelope)]);
    }
}
