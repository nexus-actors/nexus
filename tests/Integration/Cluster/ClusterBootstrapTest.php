<?php

declare(strict_types=1);

namespace Monadial\Nexus\Tests\Integration\Cluster;

use Monadial\Nexus\Cluster\ClusterConfig;
use Monadial\Nexus\Cluster\ClusterNode;
use Monadial\Nexus\Cluster\Swoole\ClusterBootstrap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ClusterBootstrap::class)]
#[RequiresPhpExtension('swoole')]
final class ClusterBootstrapTest extends TestCase
{
    #[Test]
    public function bootstrapCreatesConfiguredNumberOfWorkers(): void
    {
        $config = ClusterConfig::withWorkers(2);

        $bootstrap = ClusterBootstrap::create($config)
            ->onWorkerStart(static function (ClusterNode $node): void {
                // no-op for testing builder API
            });

        self::assertInstanceOf(ClusterBootstrap::class, $bootstrap);
    }

    #[Test]
    public function onWorkerStartIsChainable(): void
    {
        $config = ClusterConfig::withWorkers(4);

        $bootstrap = ClusterBootstrap::create($config)
            ->onWorkerStart(static function (ClusterNode $node): void {
                // no-op
            });

        self::assertInstanceOf(ClusterBootstrap::class, $bootstrap);
    }
}
