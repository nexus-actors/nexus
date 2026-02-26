<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use Monadial\Nexus\Core\Actor\ActorPath;

/**
 * @psalm-api
 */
final readonly class NodeAddress
{
    public function __construct(
        public string $cluster,
        public string $datacenter,
        public string $application,
        public string $node,
    ) {}

    public static function forWorker(int $workerId): self
    {
        return new self('local', 'local', 'nexus', "node-{$workerId}");
    }

    public function toPathPrefix(): string
    {
        return sprintf(
            '/cluster/%s/%s/%s/%s',
            self::normalize($this->cluster),
            self::normalize($this->datacenter),
            self::normalize($this->application),
            self::normalize($this->node),
        );
    }

    public function temporaryAskReplyPath(string $requestId): ActorPath
    {
        return ActorPath::fromString($this->toPathPrefix() . '/temp/remote-ask-' . $requestId);
    }

    private static function normalize(string $value): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $value);
        $trimmed = trim((string) $normalized, '-');

        return $trimmed !== ''
            ? $trimmed
            : 'unknown';
    }
}
