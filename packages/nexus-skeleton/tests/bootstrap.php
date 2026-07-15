<?php

declare(strict_types=1);

// Dual-mode bootstrap. Inside the Nexus monorepo the skeleton's nexus-actors/* deps
// are not yet Packagist-installable (published as dev-main, not tagged), so load the
// monorepo autoloader for those + symfony. Standalone (a real generated project), the
// guard is skipped and the project's own vendor provides everything.
$monorepoAutoload = '/app/vendor/autoload.php';

if (is_file($monorepoAutoload)) {
    require $monorepoAutoload;
}

require dirname(__DIR__) . '/vendor/autoload.php';
