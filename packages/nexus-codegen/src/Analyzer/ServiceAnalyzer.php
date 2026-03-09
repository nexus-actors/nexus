<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Analyzer;

use Composer\Autoload\ClassLoader;
use Monadial\Nexus\Codegen\Attribute\Actorize;
use Monadial\Nexus\Codegen\Attribute\Mutates;
use Monadial\Nexus\Codegen\Attribute\NoAsync;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;

final class ServiceAnalyzer
{
    public function __construct(
        private readonly ClassLoader $loader,
        private readonly InterfaceParser $interfaceParser,
    ) {}

    public static function fromAutoloader(): self
    {
        /** @var ClassLoader $loader */
        $loader = require 'vendor/autoload.php';
        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        return new self($loader, new InterfaceParser($parser, new TypeResolver()));
    }

    public function analyze(string $filePath): ServiceDefinition
    {
        $className = $this->extractClassName($filePath);

        require_once $filePath;

        $reflection = new ReflectionClass($className);
        $actorize = $this->readActorizeAttribute($reflection, $filePath);
        $interfaceName = $this->resolveInterface($reflection, $className);

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
            methods: $this->interfaceParser->parse($interfaceFile, $this->readMethodFlags($reflection)),
            async: $actorize->async,
            timeout: $actorize->timeout,
            supervision: $actorize->supervision,
            reset: $actorize->reset,
        );
    }

    private function extractClassName(string $filePath): string
    {
        $source = file_get_contents($filePath);

        if ($source === false) {
            throw new AnalysisException("Cannot read file {$filePath}");
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $ast = $traverser->traverse($parser->parse($source) ?? []);

        /** @var Node\Stmt\Class_|null $classNode */
        $classNode = (new NodeFinder())->findFirst($ast, static fn(Node $n) => $n instanceof Node\Stmt\Class_);

        if ($classNode?->namespacedName === null) {
            throw AnalysisException::noActorizeAttribute($filePath);
        }

        return $classNode->namespacedName->toString();
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
