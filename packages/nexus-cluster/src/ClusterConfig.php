<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster;

use InvalidArgumentException;

/**
 * @psalm-api
 * @psalm-immutable
 */
final readonly class ClusterConfig
{
    private function __construct(
        public int $workerCount,
        public int $tableSize,
        public string $socketDir,
        public string $clusterName,
        public string $applicationName,
        public string $datacenter,
        public string $nodeNamePrefix,
    ) {}

    public static function withWorkers(int $workerCount, int $tableSize = 65536, string $socketDir = ''): self
    {
        if ($workerCount < 1) {
            throw new InvalidArgumentException('Worker count must be at least 1');
        }

        $pid = getmypid();

        $resolvedSocketDir = $socketDir !== ''
            ? $socketDir
            : '/tmp/nexus-cluster-' . ($pid !== false ? $pid : 0);

        return new self(
            $workerCount,
            $tableSize,
            $resolvedSocketDir,
            clusterName: 'local',
            applicationName: 'nexus',
            datacenter: 'local',
            nodeNamePrefix: 'node',
        );
    }

    public function withIdentity(
        string $clusterName,
        string $applicationName,
        string $datacenter,
        string $nodeNamePrefix = 'node',
    ): self {
        return new self(
            $this->workerCount,
            $this->tableSize,
            $this->socketDir,
            $clusterName,
            $applicationName,
            $datacenter,
            $nodeNamePrefix,
        );
    }

    public function nodeAddressForWorker(int $workerId): NodeAddress
    {
        return new NodeAddress(
            cluster: $this->clusterName,
            datacenter: $this->datacenter,
            application: $this->applicationName,
            node: "{$this->nodeNamePrefix}-{$workerId}",
        );
    }
}
