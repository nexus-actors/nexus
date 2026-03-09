<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Definition;

use Monadial\Nexus\Core\Supervision\StrategyType;

final readonly class ServiceDefinition
{
    /** @param MethodDefinition[] $methods */
    public function __construct(
        public string $className,
        public string $shortName,
        public string $interfaceName,
        public string $outputNamespace,
        public string $outputPath,
        public array $methods,
        public bool $async,
        public int $timeout,
        public StrategyType $supervision,
        public ?bool $reset,
    ) {}
}
