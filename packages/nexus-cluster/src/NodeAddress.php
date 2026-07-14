<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use InvalidArgumentException;
use Monadial\Nexus\Core\Actor\ActorPath;

use function preg_match;
use function sprintf;
use function var_export;

/**
 * @psalm-api
 *
 * A node's cluster-wide identity: cluster / datacenter / application / node. Each segment is
 * validated to a URL-safe charset at construction, and {@see toPathPrefix()} is a lossless join —
 * so the path-prefix (which is the identity key used across membership, the endpoint registry, the
 * failure detector, and the gossip wire) is INJECTIVE: two distinct NodeAddress values can never
 * alias to the same identity. (The previous lossy `normalize()` mapped e.g. `"a b"` and `"a-b"` to
 * one prefix, a collision hazard in a membership protocol.)
 */
final readonly class NodeAddress
{
    /** Each segment must be one or more of: letters, digits, underscore, dot, hyphen. */
    private const string SEGMENT_PATTERN = '/^[a-zA-Z0-9_.-]+$/';

    /**
     * @throws InvalidArgumentException If any segment is empty or contains a character outside the
     *                                  URL-safe set `[a-zA-Z0-9_.-]` (see {@see SEGMENT_PATTERN}).
     */
    public function __construct(
        public string $cluster,
        public string $datacenter,
        public string $application,
        public string $node,
    ) {
        self::assertSegment('cluster', $cluster);
        self::assertSegment('datacenter', $datacenter);
        self::assertSegment('application', $application);
        self::assertSegment('node', $node);
    }

    public static function forWorker(int $workerId): self
    {
        return new self('local', 'local', 'nexus', "node-{$workerId}");
    }

    public function toPathPrefix(): string
    {
        return sprintf('/cluster/%s/%s/%s/%s', $this->cluster, $this->datacenter, $this->application, $this->node);
    }

    public function temporaryAskReplyPath(string $requestId): ActorPath
    {
        return ActorPath::fromString($this->toPathPrefix() . '/temp/remote-ask-' . $requestId);
    }

    private static function assertSegment(string $field, string $value): void
    {
        if (preg_match(self::SEGMENT_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf(
                    'NodeAddress %s must be a non-empty string of [a-zA-Z0-9_.-] (identity must be URL-safe '
                    . 'and collision-free); got %s.',
                    $field,
                    var_export($value, true),
                ),
            );
        }
    }
}
