<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Analyzer;

use Monadial\Nexus\Codegen\Attribute\Actorize;
use Monadial\Nexus\Codegen\Attribute\Mutates;
use Monadial\Nexus\Codegen\Attribute\NoAsync;
use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Monadial\Nexus\Core\Supervision\StrategyType;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

final class ServiceAnalyzer
{
    public function analyze(string $filePath): ServiceDefinition
    {
        $ast = $this->parse($filePath);

        $finder = new NodeFinder();

        /** @var Node\Stmt\Class_|null $classNode */
        $classNode = $finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\Class_);

        if ($classNode === null) {
            throw AnalysisException::noActorizeAttribute($filePath);
        }

        $actorizeAttr = $this->findAttribute($classNode, 'Actorize');

        if ($actorizeAttr === null) {
            throw AnalysisException::noActorizeAttribute($filePath);
        }

        $className = $classNode->namespacedName?->toString()
            ?? throw new AnalysisException("Cannot resolve class name in {$filePath}");

        $implements = $classNode->implements;

        if (count($implements) === 0) {
            throw AnalysisException::noInterface($className);
        }

        if (count($implements) > 1) {
            throw AnalysisException::multipleInterfaces($className);
        }

        $interfaceName = $implements[0]->toString();
        $interfaceFile = $this->resolveInterfaceFile($interfaceName);
        $interfaceMethods = $this->parseInterfaceMethods($interfaceFile);

        $actorize = $this->instantiateActorize($actorizeAttr);
        $shortName = $this->deriveShortName($className);
        $outputNs = $actorize->namespace ?? $this->deriveOutputNamespace($className, $shortName);
        $outputPath = $this->namespaceToPath($outputNs);

        $methodFlags = $this->extractMethodFlags($classNode);
        $methods = [];

        foreach ($interfaceMethods as $method) {
            $name = $method->name->toString();
            $flags = $methodFlags[$name] ?? ['mutates' => false, 'noAsync' => false];
            $isVoid = $method->returnType instanceof Node\Identifier && $method->returnType->name === 'void';
            $returnType = $isVoid ? null : $this->resolveType($method->returnType);

            $parameters = [];

            foreach ($method->params as $param) {
                $parameters[] = new ParameterDefinition(
                    name: $param->var instanceof Node\Expr\Variable ? (string) $param->var->name : '',
                    type: $this->resolveType($param->type),
                    nullable: $param->type instanceof Node\NullableType,
                );
            }

            $methods[] = new MethodDefinition(
                name: $name,
                pascalName: ucfirst($name),
                parameters: $parameters,
                returnType: $returnType,
                isVoid: $isVoid,
                mutates: $flags['mutates'],
                noAsync: $flags['noAsync'],
            );
        }

        return new ServiceDefinition(
            className: $className,
            shortName: $shortName,
            interfaceName: $interfaceName,
            outputNamespace: $outputNs,
            outputPath: $outputPath,
            methods: $methods,
            async: $actorize->async,
            timeout: $actorize->timeout,
            supervision: $actorize->supervision,
            reset: $actorize->reset,
        );
    }

    /** @return Node[] */
    private function parse(string $filePath): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $source = file_get_contents($filePath);

        if ($source === false) {
            throw new AnalysisException("Cannot read file {$filePath}");
        }

        return $traverser->traverse($parser->parse($source) ?? []);
    }

    private function findAttribute(Node\Stmt\Class_ $classNode, string $shortName): ?Node\Attribute
    {
        foreach ($classNode->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attrName = $attr->name->toString();

                if ($attrName === $shortName || str_ends_with($attrName, '\\' . $shortName)) {
                    return $attr;
                }
            }
        }

        return null;
    }

    private function instantiateActorize(Node\Attribute $attr): Actorize
    {
        $args = [];

        foreach ($attr->args as $arg) {
            $name = $arg->name?->toString();
            $value = $arg->value;

            if ($name === null) {
                continue;
            }

            $args[$name] = match (true) {
                $value instanceof Node\Scalar\String_ => $value->value,
                $value instanceof Node\Scalar\LNumber => $value->value,
                $value instanceof Node\Expr\ConstFetch && $value->name->toString() === 'null' => null,
                $value instanceof Node\Expr\ConstFetch => $value->name->toString() === 'true',
                default => null,
            };
        }

        $supervision = isset($args['supervision'])
            ? StrategyType::OneForOne
            : StrategyType::OneForOne;

        return new Actorize(
            async: (bool) ($args['async'] ?? true),
            supervision: $supervision,
            timeout: (int) ($args['timeout'] ?? 5),
            reset: isset($args['reset']) ? (bool) $args['reset'] : null,
            namespace: isset($args['namespace']) ? (string) $args['namespace'] : null,
        );
    }

    /** @return ClassMethod[] */
    private function parseInterfaceMethods(string $filePath): array
    {
        $ast = $this->parse($filePath);
        $finder = new NodeFinder();

        /** @var Node\Stmt\Interface_|null $iface */
        $iface = $finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\Interface_);

        if ($iface === null) {
            throw new AnalysisException("No interface found in {$filePath}");
        }

        return array_values(array_filter($iface->stmts, fn($s) => $s instanceof ClassMethod));
    }

    /** @return array<string, array{mutates: bool, noAsync: bool}> */
    private function extractMethodFlags(Node\Stmt\Class_ $classNode): array
    {
        $flags = [];

        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof ClassMethod) {
                continue;
            }

            $name = $stmt->name->toString();
            $mutates = false;
            $noAsync = false;

            foreach ($stmt->attrGroups as $group) {
                foreach ($group->attrs as $attr) {
                    $attrName = $attr->name->toString();

                    if (str_ends_with($attrName, 'Mutates')) {
                        $mutates = true;
                    }

                    if (str_ends_with($attrName, 'NoAsync')) {
                        $noAsync = true;
                    }
                }
            }

            $flags[$name] = ['mutates' => $mutates, 'noAsync' => $noAsync];
        }

        return $flags;
    }

    private function resolveType(?Node $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        return match (true) {
            $type instanceof Node\Identifier => $type->name,
            $type instanceof Node\Name\FullyQualified => '\\' . $type->toString(),
            $type instanceof Node\Name => $type->toString(),
            $type instanceof Node\NullableType => '?' . $this->resolveType($type->type),
            default => 'mixed',
        };
    }

    private function resolveInterfaceFile(string $fqcn): string
    {
        /** @var \Composer\Autoload\ClassLoader $loader */
        $loader = require 'vendor/autoload.php';
        $file = $loader->findFile($fqcn);

        if ($file !== false) {
            return $file;
        }

        throw AnalysisException::interfaceFileNotFound($fqcn);
    }

    private function deriveShortName(string $fqcn): string
    {
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);

        return str_ends_with($short, 'Service')
            ? substr($short, 0, -strlen('Service'))
            : $short;
    }

    private function deriveOutputNamespace(string $fqcn, string $shortName): string
    {
        $rootNs = substr($fqcn, 0, strrpos($fqcn, '\\'));
        $rootNs = substr($rootNs, 0, strrpos($rootNs, '\\'));

        return $rootNs . '\\Generated\\Actor\\' . $shortName;
    }

    private function namespaceToPath(string $namespace): string
    {
        return 'src/' . str_replace('\\', '/', substr($namespace, strpos($namespace, '\\') + 1));
    }
}
