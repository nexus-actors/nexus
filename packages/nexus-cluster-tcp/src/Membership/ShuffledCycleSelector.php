<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Override;

use function array_diff;
use function array_intersect;
use function array_shift;
use function array_values;
use function count;
use function in_array;
use function shuffle;

/**
 * @psalm-api
 *
 * Shuffled-cycle gossip peer selection: walks a random permutation of the peer
 * set and reshuffles when the pass is exhausted (the probe ordering used by
 * SWIM-family implementations such as HashiCorp memberlist).
 *
 * Uniform-random selection ({@see RandomPeerSelector}) makes per-peer gossip
 * inter-arrival EXPONENTIAL: with fan-out 3 of 15 peers the mean gap is short,
 * but the tail is unbounded — P(gap > maxNoHeartbeat) is ~2% per gap, so a
 * data-IDLE mesh continuously false-suspects healthy peers (measured at ~1.7
 * suspicions/s across a 16-node idle mesh; data-plane traffic masks it by
 * feeding the liveness path on every link). Cycling a permutation instead
 * bounds the gap DETERMINISTICALLY: every peer is selected at least once per
 * pass of ceil(N/count) consecutive selects, so a healthy idle link can never
 * fall silent past the failure detector's thresholds.
 *
 * Stateful by design; the MembershipActor that owns it is single-threaded, so
 * no synchronisation is needed. Peers that depart mid-pass are dropped from
 * the current pass; new peers join at the next reshuffle.
 */
final class ShuffledCycleSelector implements PeerSelector
{
    /** @var list<string> Peers not yet visited in the current pass. */
    private array $pending = [];

    /**
     * @param list<string> $peers
     *
     * @return list<string>
     * @psalm-suppress RedundantFunctionCall array_values is intentional: it returns a fresh array so
     *                 callers cannot mutate the caller's list through the result (defensive copy).
     */
    #[Override]
    public function select(array $peers, int $count): array
    {
        if ($count <= 0 || $peers === []) {
            return [];
        }

        if ($count >= count($peers)) {
            // Every peer is visited this round; start the next partial pass fresh.
            $this->pending = [];

            // Return a copy so callers cannot mutate the caller's array through the result.
            return array_values($peers);
        }

        // Drop peers that left the cluster since the current pass was shuffled.
        $this->pending = array_values(array_intersect($this->pending, $peers));

        $selected = [];

        while (count($selected) < $count) {
            if ($this->pending === []) {
                $refill = array_values(array_diff($peers, $selected));

                if ($refill === []) {
                    break;
                }

                shuffle($refill);
                $this->pending = $refill;
            }

            $next = array_shift($this->pending);

            if (!in_array($next, $selected, true)) {
                $selected[] = $next;
            }
        }

        return $selected;
    }
}
