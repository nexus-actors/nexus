<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Definition;

final readonly class ParameterDefinition
{
    public function __construct(public string $name, public string $type, public bool $nullable,) {}
}
