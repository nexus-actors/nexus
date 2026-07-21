<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * @psalm-api consumed by SetupCommand
 */
final readonly class Recipe
{
    /**
     * @param list<string> $packages composer package names to require
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $experimental,
        public array $packages,
        public ?string $configFile,
        public ?string $configTemplate,
        public string $docUrl,
    ) {}
}
