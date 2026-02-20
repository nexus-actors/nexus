<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/packages/nexus-core/src',
        __DIR__ . '/packages/nexus-core/tests',
        __DIR__ . '/packages/nexus-runtime-fiber/src',
        __DIR__ . '/packages/nexus-runtime-fiber/tests',
        __DIR__ . '/packages/nexus-runtime-step/src',
        __DIR__ . '/packages/nexus-runtime-step/tests',
        __DIR__ . '/packages/nexus-runtime-swoole/src',
        __DIR__ . '/packages/nexus-runtime-swoole/tests',
        __DIR__ . '/packages/nexus-serialization/src',
        __DIR__ . '/packages/nexus-serialization/tests',
        __DIR__ . '/packages/nexus-psalm/src',
        __DIR__ . '/packages/nexus-psalm/tests',
        __DIR__ . '/packages/nexus-cluster/src',
        __DIR__ . '/packages/nexus-cluster/tests',
        __DIR__ . '/packages/nexus-cluster-swoole/src',
        __DIR__ . '/packages/nexus-cluster-swoole/tests',
        __DIR__ . '/packages/nexus-app/src',
        __DIR__ . '/packages/nexus-app/tests',
        __DIR__ . '/packages/nexus-persistence/src',
        __DIR__ . '/packages/nexus-persistence/tests',
        __DIR__ . '/packages/nexus-persistence-dbal/src',
        __DIR__ . '/packages/nexus-persistence-dbal/tests',
        __DIR__ . '/packages/nexus-persistence-doctrine/src',
        __DIR__ . '/packages/nexus-persistence-doctrine/tests',
        __DIR__ . '/tests',
    ])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'no_unused_imports' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays', 'match', 'parameters']],
        'global_namespace_import' => [
            'import_classes' => true,
            'import_constants' => false,
            'import_functions' => false,
        ],
    ])
    ->setFinder($finder);
