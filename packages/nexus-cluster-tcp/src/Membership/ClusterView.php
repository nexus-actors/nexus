<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use DateTimeImmutable;
use Monadial\Nexus\Cluster\NodeAddress;

use function array_values;
use function ksort;

/**
 * @psalm-api
 *
 * Immutable snapshot of the cluster membership as seen by the local node.
 * Members are keyed by NodeAddress::toPathPrefix(). All mutators return a new
 * instance; the receiver is never modified.
 *
 * `merge()` implements last-writer-wins by incarnation: the record with the
 * higher incarnation supersedes; on equal incarnation the worse status wins
 * (Down > Suspect > Up); on a further tie a strictly-later `lastSeen` wins (an
 * exact tie keeps the local record). This lets gossiped views converge
 * deterministically regardless of arrival order.
 */
final readonly class ClusterView
{
    /**
     * @param array<string, MemberRecord> $members Keyed by NodeAddress::toPathPrefix().
     */
    private function __construct(public array $members) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function withMember(MemberRecord $record): self
    {
        $members = $this->members;
        $members[$record->address->toPathPrefix()] = $record;

        return new self($members);
    }

    public function withStatus(NodeAddress $address, MemberStatus $status, DateTimeImmutable $lastSeen): self
    {
        $key = $address->toPathPrefix();

        if (!isset($this->members[$key])) {
            return $this;
        }

        $members = $this->members;
        $members[$key] = $members[$key]->withStatus($status, $lastSeen);

        return new self($members);
    }

    public function withoutNode(NodeAddress $address): self
    {
        $key = $address->toPathPrefix();

        if (!isset($this->members[$key])) {
            return $this;
        }

        $members = $this->members;
        unset($members[$key]);

        return new self($members);
    }

    public function merge(self $other): self
    {
        $members = $this->members;

        foreach ($other->members as $key => $incoming) {
            $members[$key] = isset($members[$key])
                ? self::pickWinner($members[$key], $incoming)
                : $incoming;
        }

        return new self($members);
    }

    public function has(NodeAddress $address): bool
    {
        return isset($this->members[$address->toPathPrefix()]);
    }

    /**
     * @return list<MemberRecord>
     */
    public function nodes(): array
    {
        $members = $this->members;
        ksort($members);

        return array_values($members);
    }

    /**
     * @return list<MemberRecord>
     */
    public function upNodes(): array
    {
        $up = [];

        foreach ($this->nodes() as $record) {
            if ($record->status === MemberStatus::Up) {
                $up[] = $record;
            }
        }

        return $up;
    }

    private static function pickWinner(MemberRecord $current, MemberRecord $incoming): MemberRecord
    {
        if ($incoming->incarnation !== $current->incarnation) {
            return $incoming->incarnation > $current->incarnation
                ? $incoming
                : $current;
        }

        if ($incoming->status->rank() !== $current->status->rank()) {
            return $incoming->status->rank() > $current->status->rank()
                ? $incoming
                : $current;
        }

        // Strictly-greater: on an exact tie (equal incarnation, status rank, and lastSeen) the LOCAL
        // record wins. Value-equal merges are then a no-op, avoiding needless record churn; the join
        // remains commutative up to lastSeen, which is the tiebreak.
        return $incoming->lastSeen > $current->lastSeen
            ? $incoming
            : $current;
    }
}
