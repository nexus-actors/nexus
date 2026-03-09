<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Monadial\Nexus\Core\Actor\ActorRef;
use Monadial\Nexus\Core\Duration;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

final class ProxyGenerator
{
    public function generate(ServiceDefinition $definition): string
    {
        $ns = $definition->outputNamespace;
        $proxyClass = $definition->shortName . 'ServiceActorProxy';
        $asyncIface = $ns . '\\' . $definition->shortName . 'ServiceAsyncInterface';
        $timeoutNs = $definition->timeout * 1_000_000_000;

        $file = new PhpFile();
        $file->setStrictTypes();
        $file->addComment('Generated — do not edit. Re-run bin/console nexus:actorize to regenerate.');

        $namespace = $file->addNamespace($ns);
        $namespace->addUse(ActorRef::class);
        $namespace->addUse(Duration::class);
        $namespace->addUse($asyncIface);

        $class = $namespace->addClass($proxyClass);
        $class->setFinal()->addImplement($asyncIface);

        $ctor = $class->addMethod('__construct');
        $ctor->addPromotedParameter('actorRef')->setType(ActorRef::class)->setPrivate()->setReadOnly();
        $ctor->addPromotedParameter('timeout')
            ->setType(Duration::class)
            ->setPrivate()
            ->setReadOnly()
            ->setDefaultValue(new \Nette\PhpGenerator\Literal("new Duration({$timeoutNs})"));

        foreach ($definition->methods as $method) {
            $this->addSyncMethod($class, $method);

            if (!$method->isVoid && !$method->noAsync) {
                $this->addAsyncMethod($class, $method);
            }
        }

        return (new PsrPrinter())->printFile($file);
    }

    private function addSyncMethod(ClassType $class, MethodDefinition $method): void
    {
        $params = $this->buildParamList($method);
        $msgArgs = implode(', ', array_map(static fn($p) => '$' . $p->name, $method->parameters));
        $inputMsg = "new Message\\{$method->pascalName}({$msgArgs})";

        if ($method->isVoid) {
            $m = $class->addMethod($method->name)->setPublic()->setReturnType('void');
            $this->addParameters($m, $method);
            $m->setBody("\$this->actorRef->tell({$inputMsg});");

            return;
        }

        $returnType = $method->returnType ?? 'mixed';
        $m = $class->addMethod($method->name)->setPublic()->setReturnType($returnType);
        $this->addParameters($m, $method);
        $m->setBody(
            "/** @var Message\\{$method->pascalName}Response \$r */\n" .
            "\$r = \$this->actorRef->ask({$inputMsg}, \$this->timeout)->await();\n\n" .
            'return $r->result;',
        );
    }

    private function addAsyncMethod(ClassType $class, MethodDefinition $method): void
    {
        $msgArgs = implode(', ', array_map(static fn($p) => '$' . $p->name, $method->parameters));
        $inputMsg = "new Message\\{$method->pascalName}({$msgArgs})";
        $returnType = $method->returnType ?? 'mixed';

        $m = $class->addMethod($method->name . 'Async')->setPublic()->setReturnType('Future');
        $m->addComment("@return Future<{$returnType}>");
        $this->addParameters($m, $method);
        $m->setBody(
            "return \$this->actorRef->ask({$inputMsg}, \$this->timeout)\n" .
            "    ->map(static fn(Message\\{$method->pascalName}Response \$r): {$returnType} => \$r->result);",
        );
    }

    private function addParameters(\Nette\PhpGenerator\Method $m, MethodDefinition $method): void
    {
        foreach ($method->parameters as $param) {
            $m->addParameter($param->name)
                ->setType($param->type)
                ->setNullable($param->nullable);
        }
    }

    /** @return string[] */
    private function buildParamList(MethodDefinition $method): array
    {
        return array_map(
            static fn($p) => ($p->nullable ? '?' : '') . $p->type . ' $' . $p->name,
            $method->parameters,
        );
    }
}
