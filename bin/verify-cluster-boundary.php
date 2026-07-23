<?php

declare(strict_types=1);

/**
 * Regression fixture for the cluster core->transport boundary (spec §3.4).
 *
 * `nexus-cluster-tcp` splits its actor core from its transport implementations:
 * the core (Membership, protocol, messaging, ...) must stay transport-neutral,
 * while `Loopback/` and `Swoole/` provide concrete transports and `ClusterNode.php`
 * is the composition root that wires them together. Deptrac therefore forbids
 * `ClusterTcpCore -> ClusterTcpTransport`. This gate already passes on the clean
 * tree, but a passing gate proves nothing unless it also *fails* on a real
 * violation.
 *
 * This script injects an intentional `ClusterTcpCore -> ClusterTcpTransport`
 * import into `packages/nexus-cluster-tcp/src/Membership`, asserts that Deptrac
 * rejects it, then removes the fixture. Both namespaces live in one composer
 * package, so `bin/check-package-deps.php` cannot see this edge — Deptrac is
 * the only gate.
 *
 * Usage: php bin/verify-cluster-boundary.php   (run in the php container)
 * Exit:  0 = Deptrac caught the violation; 1 = it missed it.
 */

$root = dirname(__DIR__);
chdir($root);

$fixture = $root . '/packages/nexus-cluster-tcp/src/Membership/__ClusterCoreTransportBoundaryFixture.php';

$fixtureCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

use Monadial\Nexus\Cluster\Tcp\Transport\Loopback\LoopbackHub;

/**
 * INTENTIONAL core->transport boundary violation, written and removed by
 * bin/verify-cluster-boundary.php. If you are reading this in a diff, the
 * verifier crashed before cleanup — delete this file; it must never be committed.
 */
final class __ClusterCoreTransportBoundaryFixture
{
    public const string TRANSPORT = LoopbackHub::class;
}
PHP;

// Guard: never clobber a real file, and always clean up on any exit path.
if (is_file($fixture)) {
    fwrite(STDERR, "refusing to run: {$fixture} already exists\n");
    exit(2);
}

register_shutdown_function(static function () use ($fixture): void {
    if (is_file($fixture)) {
        unlink($fixture);
    }
});

file_put_contents($fixture, $fixtureCode);

/**
 * Run a command and return [exitCode, combinedOutput].
 *
 * @return array{0: int, 1: string}
 */
$run = static function (string $command): array {
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
};

$failures = [];

// Gate 1 — Deptrac must report a ClusterTcpCore -> ClusterTcpTransport violation.
[$deptracCode, $deptracOut] = $run(
    'php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress',
);

if ($deptracCode === 0 || !str_contains($deptracOut, 'must not depend on Monadial\Nexus\Cluster\Tcp\Transport')) {
    $failures[] = "Deptrac did not reject ClusterTcpCore -> ClusterTcpTransport (exit {$deptracCode}). Output:\n{$deptracOut}";
}

if ($failures !== []) {
    echo "FAIL: the cluster core->transport boundary is not enforced.\n\n";

    foreach ($failures as $failure) {
        echo $failure . "\n\n";
    }

    exit(1);
}

echo "OK: Deptrac rejects an intentional ClusterTcpCore -> ClusterTcpTransport import.\n";
exit(0);
