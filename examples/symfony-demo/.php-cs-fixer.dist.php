<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/tests']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PER-CS2.0'       => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types'        => true,
        'ordered_imports'             => ['imports_order' => ['class', 'function', 'const']],
        'trailing_comma_in_multiline' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
