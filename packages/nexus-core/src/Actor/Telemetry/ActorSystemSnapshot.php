<?php

declare(strict_types=1);

namespace Monadial\Nexus\Core\Actor\Telemetry;

/**
 * @psalm-api
 *
 * Immutable snapshot of an ActorSystem's observable state.
 */
final readonly class ActorSystemSnapshot
{
    /**
     * @param ActorSnapshot[] $actors
     */
    public function __construct(
        public string $systemName,
        public string $writerId,
        public bool $isRunning,
        public array $actors,
        public int $deadLettersCount,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            systemName: (string) $data['name'],
            writerId: (string) $data['writer_id'],
            isRunning: (bool) $data['is_running'],
            actors: array_map(
                static fn(array $a): ActorSnapshot => ActorSnapshot::fromArray($a),
                /** @var array<int, array<string, mixed>> */
                $data['actors'] ?? [],
            ),
            deadLettersCount: (int) $data['dead_letters_count'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actors' => array_values(array_map(
                static fn(ActorSnapshot $a): array => $a->toArray(),
                $this->actors,
            )),
            'dead_letters_count' => $this->deadLettersCount,
            'is_running' => $this->isRunning,
            'name' => $this->systemName,
            'writer_id' => $this->writerId,
        ];
    }
}
