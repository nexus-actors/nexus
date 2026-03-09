<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;

final class MessageGenerator
{
    public function generateInput(string $outputNamespace, MethodDefinition $method): string
    {
        $ns = $outputNamespace . '\\Message';
        $className = $method->pascalName;
        $properties = '';

        foreach ($method->parameters as $param) {
            $type = $param->nullable
                ? '?' . $param->type
                : $param->type;
            $properties .= "        public {$type} \${$param->name},\n";
        }

        $params = $properties !== ''
            ? "\n" . rtrim($properties) . "\n    "
            : '';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            // Generated — do not edit. Re-run bin/console nexus:actorize to regenerate.
            readonly class {$className}
            {
                public function __construct({$params}) {}
            }
            PHP;
    }

    public function generateResponse(string $outputNamespace, MethodDefinition $method): ?string
    {
        if ($method->isVoid) {
            return null;
        }

        $ns = $outputNamespace . '\\Message';
        $className = $method->pascalName . 'Response';
        $type = $method->returnType ?? 'mixed';

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            // Generated — do not edit. Re-run bin/console nexus:actorize to regenerate.
            readonly class {$className}
            {
                public function __construct(public {$type} \$result) {}
            }
            PHP;
    }
}
