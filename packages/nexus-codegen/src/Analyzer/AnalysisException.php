<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Analyzer;

use RuntimeException;

final class AnalysisException extends RuntimeException
{
    public static function noActorizeAttribute(string $file): self
    {
        return new self("No #[Actorize] attribute found in {$file}");
    }

    public static function noInterface(string $class): self
    {
        return new self("Class {$class} must implement exactly one interface");
    }

    public static function multipleInterfaces(string $class): self
    {
        return new self("Class {$class} implements multiple interfaces — specify one using #[Actorize(interface: X::class)]");
    }

    public static function interfaceFileNotFound(string $interface): self
    {
        return new self("Cannot locate source file for interface {$interface}");
    }
}
