<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Analyzer;

use PhpParser\Node;

final class TypeResolver
{
    public function resolve(?Node $type): string
    {
        return match (true) {
            $type === null => 'mixed',
            $type instanceof Node\Identifier => $type->name,
            $type instanceof Node\Name\FullyQualified => '\\' . $type->toString(),
            $type instanceof Node\Name => $type->toString(),
            $type instanceof Node\NullableType => '?' . $this->resolve($type->type),
            default => 'mixed',
        };
    }
}
