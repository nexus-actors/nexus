<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;

final class ActorGenerator
{
    public function generate(ServiceDefinition $definition): string
    {
        $ns = $definition->outputNamespace;
        $actorClass = $definition->shortName . 'ServiceActor';
        $serviceIface = '\\' . ltrim($definition->interfaceName, '\\');

        $matchArms = '';
        $handlers = '';

        foreach ($definition->methods as $method) {
            $inputClass = $method->pascalName;
            $matchArms .= "            \$message instanceof {$inputClass} => \$this->handle{$method->pascalName}(\$ctx, \$message),\n";
            $handlers .= $this->renderHandler($method);
        }

        $matchArms = rtrim($matchArms);

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Monadial\\Nexus\\Codegen\\Resettable;
            use Monadial\\Nexus\\Core\\Actor\\ActorContext;
            use Monadial\\Nexus\\Core\\Actor\\ActorHandler;
            use Monadial\\Nexus\\Core\\Actor\\Behavior;
            use {$ns}\\Message;

            // Generated — do not edit. Re-run bin/console nexus:actorize to regenerate.
            final class {$actorClass} implements ActorHandler
            {
                public function __construct(private readonly {$serviceIface} \$service) {}

                public function handle(ActorContext \$ctx, object \$message): Behavior
                {
                    return match (true) {
            {$matchArms}
                        default => Behavior::unhandled(),
                    };
                }
            {$handlers}
                private function resetIfNeeded(): void
                {
                    if (\$this->service instanceof Resettable) {
                        \$this->service->reset();
                    }
                }
            }
            PHP;
    }

    private function renderHandler(MethodDefinition $method): string
    {
        $inputClass = $method->pascalName;
        $args = implode(', ', array_map(fn($p) => "\$msg->{$p->name}", $method->parameters));

        $body = $method->isVoid
            ? "        \$this->service->{$method->name}({$args});"
            : "        \$ctx->reply(new Message\\{$inputClass}Response(\$this->service->{$method->name}({$args})));";

        return <<<PHP


                private function handle{$method->pascalName}(ActorContext \$ctx, Message\\{$inputClass} \$msg): Behavior
                {
                    try {
            {$body}
                    } finally {
                        \$this->resetIfNeeded();
                    }

                    return Behavior::same();
                }
            PHP;
    }
}
