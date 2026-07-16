<?php

declare(strict_types=1);

/**
 * Per-package missing-dependency check for the monorepo.
 *
 * Every package under packages/ is published independently to Packagist, so
 * each class a package uses must be resolvable from its OWN composer.json —
 * the monorepo root autoload hides violations until a standalone install breaks.
 *
 * Two complementary checks per package:
 *   1. Cross-package: every `use Monadial\Nexus\…` import in src/ must map
 *      (via the psr-4 prefixes declared by sibling packages) to a package
 *      listed in require. Own namespace is exempt.
 *   2. Third-party + extensions: composer-require-checker, with the package's
 *      vendor/ shimmed to the root vendor; Monadial\Nexus\* rows are ignored
 *      (covered by check 1 — CRC cannot resolve path-repo packages because
 *      they are absent from vendor/composer/installed.json).
 *
 * Usage: php bin/check-package-deps.php [packages/nexus-foo ...]  (default: all)
 */

$root = dirname(__DIR__);
chdir($root);

$checker = $root . '/vendor/bin/composer-require-checker';

if (!is_file($checker)) {
    fwrite(STDERR, "composer-require-checker not installed (composer install)\n");
    exit(2);
}

/** @var array<string, string> $prefixToPackage psr-4 prefix => composer package name */
$prefixToPackage = [];

$manifests = glob('packages/*/composer.json');

foreach ($manifests === false
    ? []
    : $manifests as $file) {
    /** @var array{name: string, autoload?: array{"psr-4"?: array<string, string>}} $json */
    $json = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

    foreach (array_keys($json['autoload']['psr-4'] ?? []) as $prefix) {
        $prefixToPackage[$prefix] = $json['name'];
    }
}

$targets = array_slice($argv, 1);

if ($targets === []) {
    $allPackages = glob('packages/*');
    $targets = $allPackages === false
        ? []
        : $allPackages;
}

$failures = 0;

foreach ($targets as $dir) {
    $dir = rtrim($dir, '/');

    if (!is_file($dir . '/composer.json')) {
        continue;
    }

    /** @var array{name: string, require?: array<string, string>, autoload?: array{"psr-4"?: array<string, string>}} $composer */
    $composer = json_decode((string) file_get_contents($dir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $declared = array_keys($composer['require'] ?? []);
    $ownPrefixes = array_keys($composer['autoload']['psr-4'] ?? []);
    $problems = [];

    // Symbols whitelisted for composer-require-checker also exempt the
    // cross-package check — used for runtime-guarded optional integrations
    // (extension_loaded()/class_exists() branches) that belong in `suggest`.
    $whitelist = [];

    if (is_file($dir . '/composer-require-checker.json')) {
        /** @var array{"symbol-whitelist"?: list<string>} $crcConfig */
        $crcConfig = json_decode(
            (string) file_get_contents($dir . '/composer-require-checker.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $whitelist = $crcConfig['symbol-whitelist'] ?? [];
    }

    // 1. Cross-package imports vs declared requires.
    $srcFiles = is_dir($dir . '/src')
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/src'))
        : [];

    foreach ($srcFiles as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());
        preg_match_all('/^use\s+(function\s+|const\s+)?(Monadial\\\\Nexus\\\\[\w\\\\]+)/m', $code, $m);

        foreach ($m[2] as $symbol) {
            $isOwn = array_any($ownPrefixes, static fn(string $p): bool => str_starts_with($symbol . '\\', $p));

            if ($isOwn || in_array($symbol, $whitelist, true)) {
                continue;
            }

            $match = null;

            foreach ($prefixToPackage as $prefix => $package) {
                if (str_starts_with($symbol . '\\', $prefix) || str_starts_with($symbol, $prefix)) {
                    $match = $package;

                    break;
                }
            }

            if ($match === null) {
                $problems[] = "unmapped nexus symbol: $symbol (" . $file->getPathname() . ')';
            } elseif (!in_array($match, $declared, true)) {
                $problems[] = "uses $symbol but does not require $match";
            }
        }
    }

    // 2. Third-party symbols + extensions via composer-require-checker.
    $vendorShim = $dir . '/vendor';
    $shimmed = false;

    if (!file_exists($vendorShim)) {
        $depth = substr_count($dir, '/') + 1;
        symlink(str_repeat('../', $depth) . 'vendor', $vendorShim);
        $shimmed = true;
    }

    $configOpt = is_file($dir . '/composer-require-checker.json')
        ? '--config-file ' . escapeshellarg($dir . '/composer-require-checker.json') . ' '
        : '';
    $out = [];
    exec(
        escapeshellcmd($checker) . ' check ' . $configOpt . escapeshellarg($dir . '/composer.json') . ' 2>&1',
        $out,
        $code,
    );

    if ($shimmed) {
        unlink($vendorShim);
    }

    if ($code !== 0) {
        foreach ($out as $line) {
            if (preg_match('/^\|\s*([\w\\\\]+)\s*\|/', $line, $m) !== 1 || $m[1] === 'Unknown') {
                continue;
            }

            if (!str_starts_with($m[1], 'Monadial\\Nexus\\')) {
                $problems[] = 'unknown third-party symbol: ' . $m[1];
            }
        }
    }

    $problems = array_unique($problems);

    if ($problems !== []) {
        $failures++;
        echo "== FAIL: $dir\n";

        foreach ($problems as $p) {
            echo "   $p\n";
        }
    }
}

printf("\nchecked %d packages, %d with missing dependencies\n", count($targets), $failures);
exit($failures === 0 ? 0 : 1);
