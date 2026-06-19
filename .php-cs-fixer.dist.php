<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in([
        __DIR__ . '/packages/nexus-app/src',
        __DIR__ . '/packages/nexus-app/tests',
        __DIR__ . '/packages/nexus-cluster/src',
        __DIR__ . '/packages/nexus-cluster/tests',
        __DIR__ . '/packages/nexus-core/src',
        __DIR__ . '/packages/nexus-core/tests',
        __DIR__ . '/packages/nexus-doctrine-dbal/src',
        __DIR__ . '/packages/nexus-doctrine-dbal/tests',
        __DIR__ . '/packages/nexus-doctrine-orm/src',
        __DIR__ . '/packages/nexus-doctrine-orm/tests',
        __DIR__ . '/packages/nexus-http/src',
        __DIR__ . '/packages/nexus-http/tests',
        __DIR__ . '/packages/nexus-http-auth/src',
        __DIR__ . '/packages/nexus-http-auth/tests',
        __DIR__ . '/packages/nexus-http-server-swoole/src',
        __DIR__ . '/packages/nexus-http-server-swoole/tests',
        __DIR__ . '/packages/nexus-http-server-swoole-threads/src',
        __DIR__ . '/packages/nexus-http-server-swoole-threads/tests',
        __DIR__ . '/packages/nexus-http-toolkit/src',
        __DIR__ . '/packages/nexus-http-toolkit/tests',
        __DIR__ . '/packages/nexus-http-ws/src',
        __DIR__ . '/packages/nexus-http-ws/tests',
        __DIR__ . '/packages/nexus-logger/src',
        __DIR__ . '/packages/nexus-logger/tests',
        __DIR__ . '/packages/nexus-persistence/src',
        __DIR__ . '/packages/nexus-persistence/tests',
        __DIR__ . '/packages/nexus-persistence-dbal/src',
        __DIR__ . '/packages/nexus-persistence-dbal/tests',
        __DIR__ . '/packages/nexus-persistence-doctrine/src',
        __DIR__ . '/packages/nexus-persistence-doctrine/tests',
        __DIR__ . '/packages/nexus-psalm/src',
        __DIR__ . '/packages/nexus-psalm/tests',
        __DIR__ . '/packages/nexus-runtime/src',
        __DIR__ . '/packages/nexus-runtime-fiber/src',
        __DIR__ . '/packages/nexus-runtime-fiber/tests',
        __DIR__ . '/packages/nexus-runtime-step/src',
        __DIR__ . '/packages/nexus-runtime-step/tests',
        __DIR__ . '/packages/nexus-runtime-swoole/src',
        __DIR__ . '/packages/nexus-runtime-swoole/tests',
        __DIR__ . '/packages/nexus-serialization/src',
        __DIR__ . '/packages/nexus-serialization/tests',
        __DIR__ . '/packages/nexus-worker-pool/src',
        __DIR__ . '/packages/nexus-worker-pool/tests',
        __DIR__ . '/packages/nexus-worker-pool-swoole/src',
        __DIR__ . '/packages/nexus-worker-pool-swoole/tests',
        __DIR__ . '/examples/nexus-wallet-app/src',
        __DIR__ . '/examples/nexus-wallet-app/public',
        __DIR__ . '/tests',
    ])
    ->name('*.php');

return (new Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => true,
            'import_functions' => true,
        ],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
    ])
    ->setFinder($finder);
