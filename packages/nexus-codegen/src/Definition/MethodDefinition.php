<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Definition;

final readonly class MethodDefinition
{
    /** @param array<ParameterDefinition> $parameters */
    public function __construct(
        public string $name,
        public string $pascalName,
        public array $parameters,
        public ?string $returnType,
        public bool $isVoid,
        public bool $mutates,
        public bool $noAsync,
    ) {}
}
