<?php

declare(strict_types=1);

namespace Monadial\Nexus\Observability\Data;

use JsonException;
use Monadial\Nexus\Core\Actor\Telemetry\ActorSystemSnapshot;
use Monadial\Nexus\Runtime\Swoole\Telemetry\SwooleRuntimeSnapshot;

/**
 * @psalm-api
 *
 * Typed snapshot of one worker's telemetry. Used to carry data from worker
 * threads to the aggregating HTTP server via Thread\Map JSON serialization.
 */
final readonly class WorkerTelemetryEntry
{
    public function __construct(
        public int $workerId,
        public ActorSystemSnapshot $system,
        public SwooleRuntimeSnapshot $runtime,
    ) {}

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode([
            'runtime' => $this->runtime->toArray(),
            'system' => $this->system->toArray(),
            'worker_id' => $this->workerId,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): self
    {
        /** @var array{worker_id: int, system: array<string, mixed>, runtime: array<string, int>} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self(
            workerId: $data['worker_id'],
            system: ActorSystemSnapshot::fromArray($data['system']),
            runtime: SwooleRuntimeSnapshot::fromArray($data['runtime']),
        );
    }
}
