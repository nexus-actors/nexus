<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Analyzer;

use Composer\Autoload\ClassLoader;
use Monadial\Nexus\Codegen\Attribute\Actorize;
use Monadial\Nexus\Codegen\Attribute\Mutates;
use Monadial\Nexus\Codegen\Attribute\NoAsync;
use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;

final class ServiceAnalyzer
{
    private readonly Parser $parser;

    public function __construct(private readonly ClassLoader $loader)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    public static function fromAutoloader(): self
    {
        /** @var ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        return new self($loader);
    }

    public function analyze(string $filePath): ServiceDefinition
    {
        $className = $this->extractClassName($filePath);

        require_once $filePath;

        $reflection = new ReflectionClass($className);
        $actorize = $this->readActorizeAttribute($reflection, $filePath);
        $interfaceName = $this->resolveInterface($reflection, $className);
        $methodFlags = $this->readMethodFlags($reflection);

        $interfaceFile = $this->loader->findFile($interfaceName);

        if ($interfaceFile === false) {
            throw AnalysisException::interfaceFileNotFound($interfaceName);
        }

        $shortName = $this->deriveShortName($className);
        $outputNs = $actorize->namespace ?? $this->deriveOutputNamespace($className, $shortName);

        return new ServiceDefinition(
            className: $className,
            shortName: $shortName,
            interfaceName: $interfaceName,
            outputNamespace: $outputNs,
            outputPath: $this->namespaceToPath($outputNs),
            methods: $this->parseInterfaceMethods($interfaceFile, $methodFlags),
            async: $actorize->async,
            timeout: $actorize->timeout,
            supervision: $actorize->supervision,
            reset: $actorize->reset,
        );
    }

    private function extractClassName(string $filePath): string
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $source = file_get_contents($filePath);

        if ($source === false) {
            throw new AnalysisException("Cannot read file {$filePath}");
        }

        $ast = $traverser->traverse($this->parser->parse($source) ?? []);

        /** @var Node\Stmt\Class_|null $classNode */
        $classNode = (new NodeFinder())->findFirst($ast, static fn(Node $n) => $n instanceof Node\Stmt\Class_);

        return $classNode?->namespacedName?->toString()
            ?? throw AnalysisException::noActorizeAttribute($filePath);
    }

    private function readActorizeAttribute(ReflectionClass $reflection, string $filePath): Actorize
    {
        $attrs = $reflection->getAttributes(Actorize::class);

        if ($attrs === []) {
            throw AnalysisException::noActorizeAttribute($filePath);
        }

        /** @var Actorize */
        return $attrs[0]->newInstance();
    }

    private function resolveInterface(ReflectionClass $reflection, string $className): string
    {
        $interfaces = $reflection->getInterfaceNames();

        return match (count($interfaces)) {
            0 => throw AnalysisException::noInterface($className),
            1 => $interfaces[0],
            default => throw AnalysisException::multipleInterfaces($className),
        };
    }

    /** @return array<string, array{mutates: bool, noAsync: bool}> */
    private function readMethodFlags(ReflectionClass $reflection): array
    {
        $flags = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $flags[$method->getName()] = [
                'mutates' => $method->getAttributes(Mutates::class) !== [],
                'noAsync' => $method->getAttributes(NoAsync::class) !== [],
            ];
        }

        return $flags;
    }

    /**
     * @param array<string, array{mutates: bool, noAsync: bool}> $methodFlags
     * @return MethodDefinition[]
     */
    private function parseInterfaceMethods(string $filePath, array $methodFlags): array
    {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $source = file_get_contents($filePath);

        if ($source === false) {
            throw new AnalysisException("Cannot read interface file {$filePath}");
        }

        $ast = $traverser->traverse($this->parser->parse($source) ?? []);

        /** @var Node\Stmt\Interface_|null $iface */
        $iface = (new NodeFinder())->findFirst($ast, static fn(Node $n) => $n instanceof Node\Stmt\Interface_);

        if ($iface === null) {
            throw new AnalysisException("No interface found in {$filePath}");
        }

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
                returnType: $isVoid ? null : $this->resolveType($stmt->returnType),
                isVoid: $isVoid,
                mutates: $flags['mutates'],
                noAsync: $flags['noAsync'],
            );
        }

        return $methods;
    }

    /** @return ParameterDefinition[] */
    private function parseParameters(ClassMethod $method): array
    {
        $parameters = [];

        foreach ($method->params as $param) {
            $parameters[] = new ParameterDefinition(
                name: $param->var instanceof Node\Expr\Variable ? (string) $param->var->name : '',
                type: $this->resolveType($param->type),
                nullable: $param->type instanceof Node\NullableType,
            );
        }

        return $parameters;
    }

    private function resolveType(?Node $type): string
    {
        return match (true) {
            $type === null => 'mixed',
            $type instanceof Node\Identifier => $type->name,
            $type instanceof Node\Name\FullyQualified => '\\' . $type->toString(),
            $type instanceof Node\Name => $type->toString(),
            $type instanceof Node\NullableType => '?' . $this->resolveType($type->type),
            default => 'mixed',
        };
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
        $parent = substr($fqcn, 0, strrpos($fqcn, '\\'));
        $root = substr($parent, 0, strrpos($parent, '\\'));

        return $root . '\\Generated\\Actor\\' . $shortName;
    }

    private function namespaceToPath(string $namespace): string
    {
        return 'src/' . str_replace('\\', '/', substr($namespace, strpos($namespace, '\\') + 1));
    }
}
