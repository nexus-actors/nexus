<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor\Telemetry;

/**
 * @psalm-api
 *
 * Immutable snapshot of a single actor's observable state.
 * Children are populated recursively, reflecting the real actor hierarchy.
 */
final readonly class ActorSnapshot
{
    /**
     * @param ActorSnapshot[] $children
     */
    public function __construct(
        public string $path,
        public bool $alive,
        public int $mailboxDepth,
        public int $mailboxCapacity,
        public bool $mailboxBounded,
        public array $children,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: (string) $data['path'],
            alive: (bool) $data['alive'],
            mailboxDepth: (int) $data['mailbox_depth'],
            mailboxCapacity: (int) $data['mailbox_capacity'],
            mailboxBounded: (bool) $data['mailbox_bounded'],
            children: array_map(
                static fn(array $c): self => self::fromArray($c),
                /** @var array<int, array<string, mixed>> */
                $data['children'] ?? [],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'alive' => $this->alive,
            'children' => array_values(array_map(
                static fn(self $c): array => $c->toArray(),
                $this->children,
            )),
            'mailbox_bounded' => $this->mailboxBounded,
            'mailbox_capacity' => $this->mailboxCapacity,
            'mailbox_depth' => $this->mailboxDepth,
            'path' => $this->path,
        ];
    }
}
