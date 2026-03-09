<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Monadial\Nexus\Codegen\Resettable;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

final class ActorGenerator
{
    public function generate(ServiceDefinition $definition): string
    {
        $ns = $definition->outputNamespace;
        $actorClass = $definition->shortName . 'ServiceActor';

        $file = new PhpFile();
        $file->setStrictTypes();
        $file->addComment('Generated — do not edit. Re-run bin/console nexus:actorize to regenerate.');

        $namespace = $file->addNamespace($ns);
        $namespace->addUse(Resettable::class);
        $namespace->addUse(ActorContext::class);
        $namespace->addUse(ActorHandler::class);
        $namespace->addUse(Behavior::class);
        $namespace->addUse($definition->interfaceName);

        $class = $namespace->addClass($actorClass);
        $class->setFinal()->addImplement(ActorHandler::class);

        $class->addMethod('__construct')
            ->addPromotedParameter('service')
            ->setType($definition->interfaceName)
            ->setPrivate()
            ->setReadOnly();

        $matchArms = implode("\n", array_map(
            static fn(MethodDefinition $m) => "            \$message instanceof Message\\{$m->pascalName} => \$this->handle{$m->pascalName}(\$ctx, \$message),",
            $definition->methods,
        ));

        $handleMethod = $class->addMethod('handle')->setPublic()->setReturnType(Behavior::class);
        $handleMethod->addParameter('ctx')->setType(ActorContext::class);
        $handleMethod->addParameter('message')->setType('object');
        $handleMethod->setBody(
            "return match (true) {\n{$matchArms}\n            default => Behavior::unhandled(),\n        };",
        );

        foreach ($definition->methods as $method) {
            $this->addHandler($class, $method, $ns);
        }

        $class->addMethod('resetIfNeeded')
            ->setPrivate()
            ->setReturnType('void')
            ->setBody("if (\$this->service instanceof Resettable) {\n    \$this->service->reset();\n}");

        return (new PsrPrinter())->printFile($file);
    }

    private function addHandler(ClassType $class, MethodDefinition $method, string $ns): void
    {
        $args = implode(', ', array_map(static fn($p) => "\$msg->{$p->name}", $method->parameters));

        $body = $method->isVoid
            ? "\$this->service->{$method->name}({$args});"
            : "\$ctx->reply(new Message\\{$method->pascalName}Response(\$this->service->{$method->name}({$args})));";

        $m = $class->addMethod('handle' . $method->pascalName)->setPrivate()->setReturnType(Behavior::class);
        $m->addParameter('ctx')->setType(ActorContext::class);
        $m->addParameter('msg')->setType('\\' . $ns . '\\Message\\' . $method->pascalName);
        $m->setBody("try {\n    {$body}\n} finally {\n    \$this->resetIfNeeded();\n}\n\nreturn Behavior::same();");
    }
}
