<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Runtime\Duration;

use function array_key_first;
use function count;

/**
 * @psalm-api
 *
 * Suppresses duplicate membership announcements so subscribers see each net state
 * change exactly once.
 *
 * In a gossip mesh one transient suspicion echoes: stale views keep re-teaching the
 * same (peer, incarnation) status through merges until the suspected node's
 * incarnation refutation propagates — a 16-node soak measured suspected=25–133 per
 * node per 5 minutes with only 0–3 locally-detected events (conn=0, phi=0–3). The
 * VIEW self-heals and delivery is unaffected; the event layer over-reports.
 *
 * Policy — per peer, this deduplicator tracks what was last ANNOUNCED and filters:
 *   1. News for an OLDER incarnation than already announced is suppressed (pure echo).
 *   2. A HIGHER incarnation always publishes immediately and resets the slate —
 *      refutations are never delayed.
 *   3. Within the announced incarnation, a repeat of the announced status is
 *      suppressed (the subscriber already believes it); a status CHANGE always
 *      publishes — a recovery NodeUp is never lost.
 *   4. After a Down, same-incarnation readmission churn (stale gossip re-adding the
 *      member as Suspect/Up, then re-Down) is suppressed for `downQuietPeriod`
 *      (default 30 s); a genuine rejoin arrives either after the quiet period or —
 *      once C2 wires rejoin — at a higher incarnation, and publishes.
 *
 * Confined to the membership actor (same discipline as {@see PhiAccrualDetector});
 * filters what is PUBLISHED and counted, never what the view transitions to.
 */
final class MembershipEventDeduplicator
{
    /** Bounds the slate map — same exhaustion discipline as the other unauthenticated-input caps. */
    private const int MAX_PEERS = 10_000;

    private readonly int $downQuietSeconds;

    /**
     * @var array<string, array{downAt: int|null, inc: int, status: string}>
     *      Per peer path-prefix: the incarnation and status last announced, and the
     *      unix second a Down was announced (null while not Down).
     */
    private array $slates = [];

    public function __construct(?Duration $downQuietPeriod = null)
    {
        $this->downQuietSeconds = ($downQuietPeriod ?? Duration::seconds(30))->toSeconds();
    }

    /**
     * Whether the announcement (peer, incarnation, status) is news worth publishing now.
     * Recording is implicit: a true return updates the slate.
     */
    public function shouldPublish(string $peer, int $incarnation, string $status, DateTimeImmutable $now): bool
    {
        $slate = $this->slates[$peer] ?? null;

        if ($slate === null || $incarnation > $slate['inc']) {
            $this->startSlate($peer, $incarnation, $status, $now);

            return true;
        }

        if ($incarnation < $slate['inc']) {
            return false;
        }

        if ($status === $slate['status']) {
            return false;
        }

        if ($slate['downAt'] !== null && $now->getTimestamp() - $slate['downAt'] < $this->downQuietSeconds) {
            return false;
        }

        $this->slates[$peer]['status'] = $status;
        $this->slates[$peer]['downAt'] = $status === 'down'
            ? $now->getTimestamp()
            : null;

        return true;
    }

    /**
     * Last incarnation announced for `$peer` — used for NodeDown, whose member record
     * has already been removed from the view. Defaults to 1 for unknown peers.
     */
    public function lastKnownIncarnation(string $peer): int
    {
        return $this->slates[$peer]['inc'] ?? 1;
    }

    private function startSlate(string $peer, int $incarnation, string $status, DateTimeImmutable $now): void
    {
        if (!isset($this->slates[$peer]) && count($this->slates) >= self::MAX_PEERS) {
            $oldest = array_key_first($this->slates);

            if ($oldest !== null) {
                unset($this->slates[$oldest]);
            }
        }

        unset($this->slates[$peer]); // Re-insert at the end so FIFO eviction tracks recency.
        $this->slates[$peer] = [
            'downAt' => $status === 'down'
                ? $now->getTimestamp()
                : null,
            'inc' => $incarnation,
            'status' => $status,
        ];
    }
}
