<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;

final class MessageGenerator
{
    public function generateInput(string $outputNamespace, MethodDefinition $method): string
    {
        $ns = $outputNamespace . '\\Message';

        $file = new PhpFile();
        $file->setStrictTypes();
        $file->addComment('Generated — do not edit. Re-run bin/console nexus:actorize to regenerate.');

        $class = $file->addNamespace($ns)->addClass($method->pascalName);
        $class->setReadOnly()->setFinal();

        $constructor = $class->addMethod('__construct');

        foreach ($method->parameters as $param) {
            $constructor->addPromotedParameter($param->name)
                ->setType($param->type)
                ->setNullable($param->nullable)
                ->setPublic()
                ->setReadOnly();
        }

        return (new PsrPrinter())->printFile($file);
    }

    public function generateResponse(string $outputNamespace, MethodDefinition $method): ?string
    {
        if ($method->isVoid) {
            return null;
        }

        $ns = $outputNamespace . '\\Message';
        $type = $method->returnType ?? 'mixed';

        $file = new PhpFile();
        $file->setStrictTypes();
        $file->addComment('Generated — do not edit. Re-run bin/console nexus:actorize to regenerate.');

        $class = $file->addNamespace($ns)->addClass($method->pascalName . 'Response');
        $class->setReadOnly()->setFinal();

        $class->addMethod('__construct')
            ->addPromotedParameter('result')
            ->setType($type)
            ->setPublic()
            ->setReadOnly();

        return (new PsrPrinter())->printFile($file);
    }
}
