<?php

declare(strict_types=1);

// Dual-mode bootstrap, mirroring packages/nexus-skeleton/tests/bootstrap.php. Standalone
// (a real `composer update` run inside this package) the package's own vendor/autoload.php
// already provides symfony/console AND the Nexus\Maker\{...} PSR-4 mapping declared in
// composer.json. Inside the Nexus monorepo this package has no standalone vendor/
// installed, so load the monorepo root autoloader for symfony/console and register a small
// PSR-4 shim for our own namespaces.
$ownAutoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_file($ownAutoload)) {
    require $ownAutoload;

    return;
}

require dirname(__DIR__, 3) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Nexus\\Maker\\Tests\\' => dirname(__DIR__) . '/tests/',
        'Nexus\\Maker\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }

        return;
    }
});
