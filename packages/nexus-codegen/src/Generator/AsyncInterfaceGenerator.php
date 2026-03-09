<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Nette\PhpGenerator\InterfaceType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

final class AsyncInterfaceGenerator
{
    public function generate(ServiceDefinition $definition): string
    {
        $ns = $definition->outputNamespace;
        $ifaceName = $definition->shortName . 'ServiceAsyncInterface';
        $parentIface = '\\' . ltrim($definition->interfaceName, '\\');

        $file = new PhpFile();
        $file->setStrictTypes();
        $file->addComment('Generated — do not edit. Re-run bin/console nexus:actorize to regenerate.');

        $namespace = $file->addNamespace($ns);
        $namespace->addUse($definition->interfaceName);

        $iface = $namespace->addInterface($ifaceName);
        $iface->addExtend($parentIface);

        foreach ($definition->methods as $method) {
            if ($method->isVoid || $method->noAsync) {
                continue;
            }

            $this->addAsyncMethod($iface, $method);
        }

        return (new PsrPrinter())->printFile($file);
    }

    private function addAsyncMethod(InterfaceType $iface, MethodDefinition $method): void
    {
        $returnType = $method->returnType ?? 'mixed';

        $m = $iface->addMethod($method->name . 'Async')->setPublic()->setReturnType('Future');
        $m->addComment("@return Future<{$returnType}>");

        foreach ($method->parameters as $param) {
            $m->addParameter($param->name)
                ->setType($param->type)
                ->setNullable($param->nullable);
        }
    }
}
