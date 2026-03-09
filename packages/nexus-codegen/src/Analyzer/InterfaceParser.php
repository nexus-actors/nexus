<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Analyzer;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;

final class InterfaceParser
{
    public function __construct(
        private readonly Parser $parser,
        private readonly TypeResolver $typeResolver,
    ) {}

    /**
     * @param array<string, array{mutates: bool, noAsync: bool}> $methodFlags
     * @return MethodDefinition[]
     */
    public function parse(string $filePath, array $methodFlags): array
    {
        $iface = $this->findInterface($filePath);
        $methods = [];

        foreach ($iface->stmts as $stmt) {
            if (!$stmt instanceof ClassMethod) {
                continue;
            }

            $name = $stmt->name->toString();
            $flags = $methodFlags[$name] ?? ['mutates' => false, 'noAsync' => false];
            $isVoid = $stmt->returnType instanceof Node\Identifier && $stmt->returnType->name === 'void';

            $methods[] = new MethodDefinition(
                name: $name,
                pascalName: ucfirst($name),
                parameters: $this->parseParameters($stmt),
                returnType: $isVoid ? null : $this->typeResolver->resolve($stmt->returnType),
                isVoid: $isVoid,
                mutates: $flags['mutates'],
                noAsync: $flags['noAsync'],
            );
        }

        return $methods;
    }

    private function findInterface(string $filePath): Node\Stmt\Interface_
    {
        $source = file_get_contents($filePath);

        if ($source === false) {
            throw new AnalysisException("Cannot read interface file {$filePath}");
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($this->parser->parse($source) ?? []);

        /** @var Node\Stmt\Interface_|null $iface */
        $iface = (new NodeFinder())->findFirst($ast, static fn(Node $n) => $n instanceof Node\Stmt\Interface_);

        return $iface ?? throw new AnalysisException("No interface found in {$filePath}");
    }

    /** @return ParameterDefinition[] */
    private function parseParameters(ClassMethod $method): array
    {
        return array_map(
            fn(Node\Param $param) => new ParameterDefinition(
                name: $param->var instanceof Node\Expr\Variable ? (string) $param->var->name : '',
                type: $this->typeResolver->resolve($param->type),
                nullable: $param->type instanceof Node\NullableType,
            ),
            $method->params,
        );
    }
}
