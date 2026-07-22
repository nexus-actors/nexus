<?php

declare(strict_types=1);

/**
 * Regression fixture for ARCH-002: the runtime→core boundary must be enforced.
 *
 * `nexus-runtime` is a pure leaf — it declares no `nexus-actors/core` dependency
 * and imports no Core symbols. Deptrac therefore forbids `Runtime -> Core`, and
 * the per-package dependency checker (`bin/check-package-deps.php`) would flag a
 * Core import as a missing require. Both gates already pass on the clean tree,
 * but a passing gate proves nothing unless it also *fails* on a real violation.
 *
 * This script injects an intentional `Runtime -> Core` import into
 * `packages/nexus-runtime/src`, asserts that BOTH gates reject it, then removes
 * the fixture. A monorepo-only cycle that would break a split install can no
 * longer slip past boundary analysis unnoticed.
 *
 * Usage: php bin/verify-runtime-core-boundary.php   (run in the php container)
 * Exit:  0 = both gates caught the violation; 1 = a gate missed it.
 */

$root = dirname(__DIR__);
chdir($root);

$fixture = $root . '/packages/nexus-runtime/src/__RuntimeCoreBoundaryFixture.php';

$fixtureCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime;

use Monadial\Nexus\Core\Actor\ActorSystem;

/**
 * INTENTIONAL runtime->core boundary violation, written and removed by
 * bin/verify-runtime-core-boundary.php. If you are reading this in a diff, the
 * verifier crashed before cleanup — delete this file; it must never be committed.
 */
final class __RuntimeCoreBoundaryFixture
{
    public const string CORE = ActorSystem::class;
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

// Gate 1 — Deptrac must report a Runtime -> Core violation.
[$deptracCode, $deptracOut] = $run(
    'php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/deptrac analyse --no-progress',
);

if ($deptracCode === 0 || !str_contains($deptracOut, 'must not depend on Monadial\Nexus\Core')) {
    $failures[] = "Deptrac did not reject Runtime -> Core (exit {$deptracCode}). Output:\n{$deptracOut}";
}

// Gate 2 — the per-package dependency checker must flag the missing require.
[$depsCode, $depsOut] = $run('php bin/check-package-deps.php packages/nexus-runtime');

if ($depsCode === 0 || !str_contains($depsOut, 'does not require nexus-actors/core')) {
    $failures[] = "check-package-deps did not flag the missing nexus-actors/core require (exit {$depsCode}). Output:\n{$depsOut}";
}

if ($failures !== []) {
    echo "FAIL: the runtime->core boundary is not enforced.\n\n";

    foreach ($failures as $failure) {
        echo $failure . "\n\n";
    }

    exit(1);
}

echo "OK: both Deptrac and check-package-deps reject an intentional Runtime -> Core import.\n";
exit(0);
