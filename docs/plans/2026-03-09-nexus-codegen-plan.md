# nexus-codegen Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Generate actor message classes, actor handlers, async interfaces, and proxy classes from `#[Actorize]`-annotated PHP service classes using AST analysis.

**Architecture:** Two packages — `nexus-codegen` (standalone, no Symfony dep) uses `nikic/php-parser` to read service interfaces and emit PHP files via `nette/php-generator`; `nexus-symfony-codegen` adds a Symfony compiler pass that auto-regenerates stale files on `cache:warmup` and registers the proxy as the service interface alias. All generated files are real PHP committed to source control — no runtime magic.

**Tech Stack:** PHP 8.5+, `nikic/php-parser` ^5.0, `nette/php-generator` ^4.0, `symfony/dependency-injection` (bridge only)

**Design doc:** `docs/plans/2026-03-09-nexus-codegen-design.md`

---

## Setup: Create worktree

```bash
git worktree add .worktrees/feat/nexus-codegen -b feat/nexus-codegen
cd .worktrees/feat/nexus-codegen
```

All subsequent work happens inside this worktree.

---

## Task 1: Package scaffold — nexus-codegen

**Files:**
- Create: `packages/nexus-codegen/composer.json`
- Create: `packages/nexus-codegen/src/.gitkeep`
- Create: `packages/nexus-codegen/tests/.gitkeep`
- Modify: `composer.json` (root) — add autoload entries
- Modify: `phpunit.xml` — add test suite entry
- Modify: `deptrac.yaml` — add Codegen layer

**Step 1: Create `packages/nexus-codegen/composer.json`**

```json
{
    "name": "nexus-actors/codegen",
    "description": "AST-based code generator — actorizes PHP services automatically",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "nexus-actors/core": "dev-main",
        "nexus-actors/runtime": "dev-main",
        "nikic/php-parser": "^5.0",
        "nette/php-generator": "^4.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Codegen\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Codegen\\Tests\\": "tests/"
        }
    }
}
```

**Step 2: Add to root `composer.json` autoload sections**

In `autoload.psr-4` add (keep alphabetical order):
```json
"Monadial\\Nexus\\Codegen\\": "packages/nexus-codegen/src/",
```

In `autoload-dev.psr-4` add:
```json
"Monadial\\Nexus\\Codegen\\Tests\\": "packages/nexus-codegen/tests/",
```

**Step 3: Add to `phpunit.xml`**

In the `unit` testsuite `<testsuite>` block, add:
```xml
<directory>packages/nexus-codegen/tests/Unit</directory>
```

In the `<source><include>` block, add:
```xml
<directory>packages/nexus-codegen/src</directory>
```

**Step 4: Add to `deptrac.yaml`**

In the `layers` section add after the existing App layer:
```yaml
- name: Codegen
  collectors:
    - type: directory
      value: packages/nexus-codegen/src/.*
```

In the `ruleset` section add:
```yaml
Codegen:
  - Core
  - Runtime
```

**Step 5: Run composer install**

```bash
docker compose exec php composer install
```

Expected: no errors, `packages/nexus-codegen` appears in autoload map.

**Step 6: Commit**

```bash
git add packages/nexus-codegen/ composer.json phpunit.xml deptrac.yaml
git commit -m "feat(codegen): scaffold nexus-codegen package"
```

---

## Task 2: Attributes and Resettable interface

**Files:**
- Create: `packages/nexus-codegen/src/Attribute/Actorize.php`
- Create: `packages/nexus-codegen/src/Attribute/Mutates.php`
- Create: `packages/nexus-codegen/src/Attribute/Query.php`
- Create: `packages/nexus-codegen/src/Attribute/NoAsync.php`
- Create: `packages/nexus-codegen/src/Resettable.php`
- Create: `packages/nexus-codegen/tests/Unit/Attribute/ActorizeTest.php`

**Step 1: Write failing test**

```php
// packages/nexus-codegen/tests/Unit/Attribute/ActorizeTest.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Attribute;

use Monadial\Nexus\Codegen\Attribute\Actorize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Actorize::class)]
final class ActorizeTest extends TestCase
{
    #[Test]
    public function defaults_are_applied(): void
    {
        $attr = new Actorize();

        self::assertTrue($attr->async);
        self::assertSame('one-for-one', $attr->supervision);
        self::assertSame(5, $attr->timeout);
        self::assertNull($attr->reset);
        self::assertNull($attr->namespace);
    }

    #[Test]
    public function values_are_set(): void
    {
        $attr = new Actorize(async: false, supervision: 'backoff', timeout: 10, reset: true, namespace: 'App\\Gen');

        self::assertFalse($attr->async);
        self::assertSame('backoff', $attr->supervision);
        self::assertSame(10, $attr->timeout);
        self::assertTrue($attr->reset);
        self::assertSame('App\\Gen', $attr->namespace);
    }
}
```

**Step 2: Run to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Attribute/ActorizeTest.php
```

Expected: FAIL — class not found.

**Step 3: Implement attributes**

```php
// packages/nexus-codegen/src/Attribute/Actorize.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Actorize
{
    public function __construct(
        public readonly bool $async = true,
        public readonly string $supervision = 'one-for-one',
        public readonly int $timeout = 5,
        public readonly ?bool $reset = null,
        public readonly ?string $namespace = null,
    ) {}
}
```

```php
// packages/nexus-codegen/src/Attribute/Mutates.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Mutates {}
```

```php
// packages/nexus-codegen/src/Attribute/Query.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class Query {}
```

```php
// packages/nexus-codegen/src/Attribute/NoAsync.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class NoAsync {}
```

```php
// packages/nexus-codegen/src/Resettable.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen;

interface Resettable
{
    public function reset(): void;
}
```

**Step 4: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Attribute/ActorizeTest.php
```

Expected: PASS.

**Step 5: Commit**

```bash
git add packages/nexus-codegen/
git commit -m "feat(codegen): add Actorize/Mutates/Query/NoAsync attributes and Resettable interface"
```

---

## Task 3: Value objects — ServiceDefinition, MethodDefinition, ParameterDefinition

These are the data model the analyzer produces and the generators consume.

**Files:**
- Create: `packages/nexus-codegen/src/Definition/ParameterDefinition.php`
- Create: `packages/nexus-codegen/src/Definition/MethodDefinition.php`
- Create: `packages/nexus-codegen/src/Definition/ServiceDefinition.php`
- Create: `packages/nexus-codegen/tests/Unit/Definition/ServiceDefinitionTest.php`

**Step 1: Write failing test**

```php
// packages/nexus-codegen/tests/Unit/Definition/ServiceDefinitionTest.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Definition;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceDefinition::class)]
#[CoversClass(MethodDefinition::class)]
#[CoversClass(ParameterDefinition::class)]
final class ServiceDefinitionTest extends TestCase
{
    #[Test]
    public function service_definition_holds_expected_values(): void
    {
        $param = new ParameterDefinition('id', 'string', false);
        $method = new MethodDefinition(
            name: 'getProduct',
            pascalName: 'GetProduct',
            parameters: [$param],
            returnType: 'App\\Entity\\Product',
            isVoid: false,
            mutates: false,
            noAsync: false,
        );
        $service = new ServiceDefinition(
            className: 'App\\Service\\ProductService',
            shortName: 'Product',
            interfaceName: 'App\\Service\\ProductServiceInterface',
            outputNamespace: 'App\\Generated\\Actor\\Product',
            outputPath: 'src/Generated/Actor/Product',
            methods: [$method],
            async: true,
            timeout: 5,
            supervision: 'one-for-one',
            reset: null,
        );

        self::assertSame('App\\Service\\ProductService', $service->className);
        self::assertSame('Product', $service->shortName);
        self::assertSame([$method], $service->methods);
        self::assertFalse($method->isVoid);
        self::assertSame('string', $param->type);
    }

    #[Test]
    public function method_definition_identifies_void(): void
    {
        $method = new MethodDefinition(
            name: 'deleteProduct',
            pascalName: 'DeleteProduct',
            parameters: [new ParameterDefinition('id', 'string', false)],
            returnType: null,
            isVoid: true,
            mutates: true,
            noAsync: false,
        );

        self::assertTrue($method->isVoid);
        self::assertNull($method->returnType);
    }
}
```

**Step 2: Run to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Definition/ServiceDefinitionTest.php
```

Expected: FAIL — classes not found.

**Step 3: Implement value objects**

```php
// packages/nexus-codegen/src/Definition/ParameterDefinition.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Definition;

final readonly class ParameterDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
    ) {}
}
```

```php
// packages/nexus-codegen/src/Definition/MethodDefinition.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Definition;

final readonly class MethodDefinition
{
    /** @param ParameterDefinition[] $parameters */
    public function __construct(
        public string $name,
        public string $pascalName,
        public array $parameters,
        public ?string $returnType,
        public bool $isVoid,
        public bool $mutates,
        public bool $noAsync,
    ) {}
}
```

```php
// packages/nexus-codegen/src/Definition/ServiceDefinition.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Definition;

final readonly class ServiceDefinition
{
    /** @param MethodDefinition[] $methods */
    public function __construct(
        public string $className,
        public string $shortName,
        public string $interfaceName,
        public string $outputNamespace,
        public string $outputPath,
        public array $methods,
        public bool $async,
        public int $timeout,
        public string $supervision,
        public ?bool $reset,
    ) {}
}
```

**Step 4: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Definition/ServiceDefinitionTest.php
```

Expected: PASS.

**Step 5: Commit**

```bash
git add packages/nexus-codegen/
git commit -m "feat(codegen): add ServiceDefinition, MethodDefinition, ParameterDefinition value objects"
```

---

## Task 4: ServiceAnalyzer — AST-based extraction

Reads a PHP file, finds `#[Actorize]`, finds the implemented interface, parses the interface, and returns a `ServiceDefinition`.

**Files:**
- Create: `packages/nexus-codegen/src/Analyzer/ServiceAnalyzer.php`
- Create: `packages/nexus-codegen/src/Analyzer/AnalysisException.php`
- Create: `packages/nexus-codegen/tests/Unit/Analyzer/ServiceAnalyzerTest.php`
- Create: `packages/nexus-codegen/tests/Fixture/ProductServiceInterface.php`
- Create: `packages/nexus-codegen/tests/Fixture/ProductService.php`

**Step 1: Create fixture files**

```php
// packages/nexus-codegen/tests/Fixture/ProductServiceInterface.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Fixture;

interface ProductServiceInterface
{
    public function getProduct(string $id): Product;

    public function createProduct(string $name, float $price): Product;

    public function deleteProduct(string $id): void;
}
```

```php
// packages/nexus-codegen/tests/Fixture/Product.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Fixture;

final readonly class Product
{
    public function __construct(
        public string $id,
        public string $name,
        public float $price,
    ) {}
}
```

```php
// packages/nexus-codegen/tests/Fixture/ProductService.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Fixture;

use Monadial\Nexus\Codegen\Attribute\Actorize;
use Monadial\Nexus\Codegen\Attribute\Mutates;

#[Actorize(async: true, timeout: 5, namespace: 'Monadial\\Nexus\\Codegen\\Tests\\Fixture\\Generated')]
final class ProductService implements ProductServiceInterface
{
    public function getProduct(string $id): Product
    {
        return new Product($id, 'Test', 9.99);
    }

    #[Mutates]
    public function createProduct(string $name, float $price): Product
    {
        return new Product('new-id', $name, $price);
    }

    #[Mutates]
    public function deleteProduct(string $id): void {}
}
```

**Step 2: Write failing test**

```php
// packages/nexus-codegen/tests/Unit/Analyzer/ServiceAnalyzerTest.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Analyzer;

use Monadial\Nexus\Codegen\Analyzer\ServiceAnalyzer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceAnalyzer::class)]
final class ServiceAnalyzerTest extends TestCase
{
    private ServiceAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ServiceAnalyzer();
    }

    #[Test]
    public function analyzes_service_class_into_definition(): void
    {
        $file = __DIR__ . '/../../Fixture/ProductService.php';

        $definition = $this->analyzer->analyze($file);

        self::assertSame('Monadial\\Nexus\\Codegen\\Tests\\Fixture\\ProductService', $definition->className);
        self::assertSame('Monadial\\Nexus\\Codegen\\Tests\\Fixture\\ProductServiceInterface', $definition->interfaceName);
        self::assertSame('Product', $definition->shortName);
        self::assertCount(3, $definition->methods);
    }

    #[Test]
    public function extracts_method_signatures(): void
    {
        $definition = $this->analyzer->analyze(__DIR__ . '/../../Fixture/ProductService.php');

        $get = $definition->methods[0];
        self::assertSame('getProduct', $get->name);
        self::assertSame('GetProduct', $get->pascalName);
        self::assertFalse($get->isVoid);
        self::assertFalse($get->mutates);
        self::assertCount(1, $get->parameters);
        self::assertSame('id', $get->parameters[0]->name);
        self::assertSame('string', $get->parameters[0]->type);
    }

    #[Test]
    public function detects_void_and_mutates(): void
    {
        $definition = $this->analyzer->analyze(__DIR__ . '/../../Fixture/ProductService.php');

        $delete = $definition->methods[2];
        self::assertSame('deleteProduct', $delete->name);
        self::assertTrue($delete->isVoid);
        self::assertTrue($delete->mutates);
    }

    #[Test]
    public function throws_when_no_actorize_attribute(): void
    {
        $this->expectException(\Monadial\Nexus\Codegen\Analyzer\AnalysisException::class);

        $this->analyzer->analyze(__DIR__ . '/../../Fixture/ProductServiceInterface.php');
    }
}
```

**Step 3: Run to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Analyzer/ServiceAnalyzerTest.php
```

Expected: FAIL — class not found.

**Step 4: Implement AnalysisException**

```php
// packages/nexus-codegen/src/Analyzer/AnalysisException.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Analyzer;

use RuntimeException;

final class AnalysisException extends RuntimeException
{
    public static function noActorizeAttribute(string $file): self
    {
        return new self("No #[Actorize] attribute found in {$file}");
    }

    public static function noInterface(string $class): self
    {
        return new self("Class {$class} must implement exactly one interface");
    }

    public static function multipleInterfaces(string $class): self
    {
        return new self("Class {$class} implements multiple interfaces — specify one using #[Actorize(interface: X::class)]");
    }

    public static function interfaceFileNotFound(string $interface): self
    {
        return new self("Cannot locate source file for interface {$interface}");
    }
}
```

**Step 5: Implement ServiceAnalyzer**

```php
// packages/nexus-codegen/src/Analyzer/ServiceAnalyzer.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Analyzer;

use Monadial\Nexus\Codegen\Attribute\Actorize;
use Monadial\Nexus\Codegen\Attribute\Mutates;
use Monadial\Nexus\Codegen\Attribute\NoAsync;
use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

final class ServiceAnalyzer
{
    public function analyze(string $filePath): ServiceDefinition
    {
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $ast = $parser->parse(file_get_contents($filePath) ?: '');

        if ($ast === null) {
            throw new AnalysisException("Failed to parse {$filePath}");
        }

        $ast    = $traverser->traverse($ast);
        $finder = new NodeFinder();

        /** @var Node\Stmt\Class_|null $classNode */
        $classNode = $finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\Class_);

        if ($classNode === null) {
            throw AnalysisException::noActorizeAttribute($filePath);
        }

        $actorizeAttr = $this->findAttribute($classNode, Actorize::class);

        if ($actorizeAttr === null) {
            throw AnalysisException::noActorizeAttribute($filePath);
        }

        $className = $classNode->namespacedName?->toString() ?? throw new AnalysisException("Cannot resolve class name in {$filePath}");

        $implements = $classNode->implements;

        if (count($implements) === 0) {
            throw AnalysisException::noInterface($className);
        }

        if (count($implements) > 1) {
            throw AnalysisException::multipleInterfaces($className);
        }

        $interfaceName = $implements[0]->toString();

        // Find interface file via autoloader
        $interfaceFile = $this->resolveInterfaceFile($interfaceName);
        $interfaceMethods = $this->parseInterfaceMethods($interfaceFile, $interfaceName);

        // Parse #[Actorize] args
        $actorize = $this->instantiateActorize($actorizeAttr);

        $shortName     = $this->deriveShortName($className);
        $outputNs      = $actorize->namespace ?? $this->deriveOutputNamespace($className, $shortName);
        $outputPath    = $this->namespaceToPath($outputNs);

        // Check which methods have #[Mutates] or #[NoAsync] on the concrete class
        $methodFlags = $this->extractMethodFlags($classNode);

        $methods = [];

        foreach ($interfaceMethods as $method) {
            $name    = $method->name->toString();
            $flags   = $methodFlags[$name] ?? ['mutates' => false, 'noAsync' => false];
            $isVoid  = $method->returnType instanceof Node\Identifier && $method->returnType->name === 'void';
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

    private function findAttribute(Node\Stmt\Class_ $classNode, string $attrClass): ?Node\Attribute
    {
        foreach ($classNode->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if ($attr->name->toString() === $attrClass || str_ends_with($attr->name->toString(), '\\' . class_basename($attrClass))) {
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
                $value instanceof Node\Expr\ConstFetch => $value->name->toString() === 'true',
                $value instanceof Node\Expr\ConstFetch && $value->name->toString() === 'null' => null,
                default => null,
            };
        }

        return new Actorize(
            async: $args['async'] ?? true,
            supervision: $args['supervision'] ?? 'one-for-one',
            timeout: $args['timeout'] ?? 5,
            reset: $args['reset'] ?? null,
            namespace: $args['namespace'] ?? null,
        );
    }

    /** @return ClassMethod[] */
    private function parseInterfaceMethods(string $filePath, string $interfaceName): array
    {
        $parser    = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        $ast = $traverser->traverse($parser->parse(file_get_contents($filePath) ?: '') ?? []);
        $finder = new NodeFinder();

        /** @var Node\Stmt\Interface_|null $iface */
        $iface = $finder->findFirst($ast, fn(Node $n) => $n instanceof Node\Stmt\Interface_);

        if ($iface === null) {
            throw new AnalysisException("No interface found in {$filePath}");
        }

        return array_filter($iface->stmts, fn($s) => $s instanceof ClassMethod);
    }

    /** @return array<string, array{mutates: bool, noAsync: bool}> */
    private function extractMethodFlags(Node\Stmt\Class_ $classNode): array
    {
        $flags = [];

        foreach ($classNode->stmts as $stmt) {
            if (!$stmt instanceof ClassMethod) {
                continue;
            }

            $name    = $stmt->name->toString();
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
            $type instanceof Node\Identifier         => $type->name,
            $type instanceof Node\Name\FullyQualified => '\\' . $type->toString(),
            $type instanceof Node\Name               => $type->toString(),
            $type instanceof Node\NullableType       => '?' . $this->resolveType($type->type),
            default                                  => 'mixed',
        };
    }

    private function resolveInterfaceFile(string $fqcn): string
    {
        $path = str_replace('\\', '/', $fqcn) . '.php';

        // Try composer autoloader
        foreach (get_declared_classes() as $class) {
            if ($class === 'Composer\Autoload\ClassLoader') {
                $loader = include 'vendor/autoload.php';
                $file   = $loader->findFile($fqcn);

                if ($file !== false) {
                    return $file;
                }
            }
        }

        // Fallback: try common src paths
        foreach (['src/', 'packages/'] as $prefix) {
            $candidate = $prefix . $path;

            if (file_exists($candidate)) {
                return $candidate;
            }
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
```

**Step 6: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Analyzer/ServiceAnalyzerTest.php
```

Expected: PASS (4 tests).

**Step 7: Commit**

```bash
git add packages/nexus-codegen/
git commit -m "feat(codegen): add ServiceAnalyzer — AST-based service definition extraction"
```

---

## Task 5: Message generator

Generates input message classes and response message classes.

**Files:**
- Create: `packages/nexus-codegen/src/Generator/MessageGenerator.php`
- Create: `packages/nexus-codegen/tests/Unit/Generator/MessageGeneratorTest.php`

**Step 1: Write failing test**

```php
// packages/nexus-codegen/tests/Unit/Generator/MessageGeneratorTest.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Generator\MessageGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageGenerator::class)]
final class MessageGeneratorTest extends TestCase
{
    private MessageGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new MessageGenerator();
    }

    #[Test]
    public function generates_input_message(): void
    {
        $method = new MethodDefinition(
            name: 'getProduct',
            pascalName: 'GetProduct',
            parameters: [new ParameterDefinition('id', 'string', false)],
            returnType: '\\App\\Entity\\Product',
            isVoid: false,
            mutates: false,
            noAsync: false,
        );

        $code = $this->generator->generateInput('App\\Generated\\Actor\\Product', $method);

        self::assertStringContainsString('readonly class GetProduct', $code);
        self::assertStringContainsString('public string $id', $code);
        self::assertStringContainsString("namespace App\\Generated\\Actor\\Product\\Message", $code);
    }

    #[Test]
    public function generates_response_message(): void
    {
        $method = new MethodDefinition(
            name: 'getProduct',
            pascalName: 'GetProduct',
            parameters: [],
            returnType: '\\App\\Entity\\Product',
            isVoid: false,
            mutates: false,
            noAsync: false,
        );

        $code = $this->generator->generateResponse('App\\Generated\\Actor\\Product', $method);

        self::assertStringContainsString('readonly class GetProductResponse', $code);
        self::assertStringContainsString('public \\App\\Entity\\Product $result', $code);
    }

    #[Test]
    public function void_method_has_no_response(): void
    {
        $method = new MethodDefinition(
            name: 'deleteProduct',
            pascalName: 'DeleteProduct',
            parameters: [new ParameterDefinition('id', 'string', false)],
            returnType: null,
            isVoid: true,
            mutates: true,
            noAsync: false,
        );

        self::assertNull($this->generator->generateResponse('App\\Generated\\Actor\\Product', $method));
    }
}
```

**Step 2: Run to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Generator/MessageGeneratorTest.php
```

**Step 3: Implement MessageGenerator**

```php
// packages/nexus-codegen/src/Generator/MessageGenerator.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;

final class MessageGenerator
{
    public function generateInput(string $outputNamespace, MethodDefinition $method): string
    {
        $ns         = $outputNamespace . '\\Message';
        $className  = $method->pascalName;
        $properties = '';
        $params     = '';

        foreach ($method->parameters as $i => $param) {
            $type       = $param->nullable ? '?' . $param->type : $param->type;
            $properties .= "        public {$type} \${$param->name},\n";
        }

        if ($properties !== '') {
            $params = "\n" . rtrim($properties) . "\n    ";
        }

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

        $ns        = $outputNamespace . '\\Message';
        $className = $method->pascalName . 'Response';
        $type      = $method->returnType ?? 'mixed';

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
```

**Step 4: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Generator/MessageGeneratorTest.php
```

Expected: PASS.

**Step 5: Commit**

```bash
git add packages/nexus-codegen/
git commit -m "feat(codegen): add MessageGenerator for input and response message classes"
```

---

## Task 6: Actor generator

Generates `ProductServiceActor` — the `ActorHandler` that wraps the service.

**Files:**
- Create: `packages/nexus-codegen/src/Generator/ActorGenerator.php`
- Create: `packages/nexus-codegen/tests/Unit/Generator/ActorGeneratorTest.php`

**Step 1: Write failing test**

```php
// packages/nexus-codegen/tests/Unit/Generator/ActorGeneratorTest.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Monadial\Nexus\Codegen\Generator\ActorGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorGenerator::class)]
final class ActorGeneratorTest extends TestCase
{
    #[Test]
    public function generates_actor_class(): void
    {
        $definition = $this->makeDefinition();
        $generator  = new ActorGenerator();

        $code = $generator->generate($definition);

        self::assertStringContainsString('final class ProductServiceActor implements ActorHandler', $code);
        self::assertStringContainsString('ProductServiceInterface $service', $code);
        self::assertStringContainsString('$message instanceof GetProduct', $code);
        self::assertStringContainsString('$message instanceof DeleteProduct', $code);
        self::assertStringContainsString('$ctx->reply(new GetProductResponse', $code);
        self::assertStringContainsString('resetIfNeeded', $code);
    }

    #[Test]
    public function void_handler_has_no_reply(): void
    {
        $definition = $this->makeDefinition();
        $generator  = new ActorGenerator();

        $code = $generator->generate($definition);

        self::assertStringNotContainsString('DeleteProductResponse', $code);
        self::assertStringContainsString('$this->service->deleteProduct(', $code);
    }

    private function makeDefinition(): ServiceDefinition
    {
        return new ServiceDefinition(
            className: 'App\\Service\\ProductService',
            shortName: 'Product',
            interfaceName: 'App\\Service\\ProductServiceInterface',
            outputNamespace: 'App\\Generated\\Actor\\Product',
            outputPath: 'src/Generated/Actor/Product',
            methods: [
                new MethodDefinition('getProduct', 'GetProduct', [new ParameterDefinition('id', 'string', false)], '\\App\\Entity\\Product', false, false, false),
                new MethodDefinition('deleteProduct', 'DeleteProduct', [new ParameterDefinition('id', 'string', false)], null, true, true, false),
            ],
            async: true,
            timeout: 5,
            supervision: 'one-for-one',
            reset: null,
        );
    }
}
```

**Step 2: Run to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Generator/ActorGeneratorTest.php
```

**Step 3: Implement ActorGenerator**

```php
// packages/nexus-codegen/src/Generator/ActorGenerator.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;

final class ActorGenerator
{
    public function generate(ServiceDefinition $definition): string
    {
        $ns            = $definition->outputNamespace;
        $actorClass    = $definition->shortName . 'ServiceActor';
        $serviceIface  = '\\' . ltrim($definition->interfaceName, '\\');
        $msgNs         = $ns . '\\Message';

        $matchArms    = '';
        $handlers     = '';

        foreach ($definition->methods as $method) {
            $inputClass = $method->pascalName;
            $matchArms .= "            \$message instanceof {$inputClass} => \$this->handle{$method->pascalName}(\$ctx, \$message),\n";
            $handlers  .= $this->renderHandler($method, $msgNs);
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

    private function renderHandler(MethodDefinition $method, string $msgNs): string
    {
        $inputClass = $method->pascalName;
        $args       = implode(', ', array_map(fn($p) => "\$msg->{$p->name}", $method->parameters));

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
```

**Step 4: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Generator/ActorGeneratorTest.php
```

Expected: PASS.

**Step 5: Commit**

```bash
git add packages/nexus-codegen/
git commit -m "feat(codegen): add ActorGenerator"
```

---

## Task 7: Async interface and proxy generators

**Files:**
- Create: `packages/nexus-codegen/src/Generator/AsyncInterfaceGenerator.php`
- Create: `packages/nexus-codegen/src/Generator/ProxyGenerator.php`
- Create: `packages/nexus-codegen/tests/Unit/Generator/AsyncInterfaceGeneratorTest.php`
- Create: `packages/nexus-codegen/tests/Unit/Generator/ProxyGeneratorTest.php`

**Step 1: Write failing tests**

```php
// packages/nexus-codegen/tests/Unit/Generator/AsyncInterfaceGeneratorTest.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Generator;

use Monadial\Nexus\Codegen\Definition\MethodDefinition;
use Monadial\Nexus\Codegen\Definition\ParameterDefinition;
use Monadial\Nexus\Codegen\Definition\ServiceDefinition;
use Monadial\Nexus\Codegen\Generator\AsyncInterfaceGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsyncInterfaceGenerator::class)]
final class AsyncInterfaceGeneratorTest extends TestCase
{
    #[Test]
    public function generates_async_interface_extending_original(): void
    {
        $definition = new ServiceDefinition(
            className: 'App\\Service\\ProductService',
            shortName: 'Product',
            interfaceName: 'App\\Service\\ProductServiceInterface',
            outputNamespace: 'App\\Generated\\Actor\\Product',
            outputPath: 'src/Generated/Actor/Product',
            methods: [
                new MethodDefinition('getProduct', 'GetProduct', [new ParameterDefinition('id', 'string', false)], '\\App\\Entity\\Product', false, false, false),
                new MethodDefinition('deleteProduct', 'DeleteProduct', [new ParameterDefinition('id', 'string', false)], null, true, true, false),
            ],
            async: true,
            timeout: 5,
            supervision: 'one-for-one',
            reset: null,
        );

        $code = (new AsyncInterfaceGenerator())->generate($definition);

        self::assertStringContainsString('interface ProductServiceAsyncInterface extends \\App\\Service\\ProductServiceInterface', $code);
        self::assertStringContainsString('getProductAsync(string $id): Future', $code);
        // void methods have no async variant
        self::assertStringNotContainsString('deleteProductAsync', $code);
    }

    #[Test]
    public function no_async_methods_excluded(): void
    {
        $definition = new ServiceDefinition(
            className: 'App\\Service\\ProductService',
            shortName: 'Product',
            interfaceName: 'App\\Service\\ProductServiceInterface',
            outputNamespace: 'App\\Generated\\Actor\\Product',
            outputPath: 'src/Generated/Actor/Product',
            methods: [
                new MethodDefinition('getProduct', 'GetProduct', [], '\\App\\Entity\\Product', false, false, true), // noAsync: true
            ],
            async: true,
            timeout: 5,
            supervision: 'one-for-one',
            reset: null,
        );

        $code = (new AsyncInterfaceGenerator())->generate($definition);

        self::assertStringNotContainsString('getProductAsync', $code);
    }
}
```

**Step 2: Run to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Generator/AsyncInterfaceGeneratorTest.php
```

**Step 3: Implement AsyncInterfaceGenerator**

```php
// packages/nexus-codegen/src/Generator/AsyncInterfaceGenerator.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\ServiceDefinition;

final class AsyncInterfaceGenerator
{
    public function generate(ServiceDefinition $definition): string
    {
        $ns           = $definition->outputNamespace;
        $ifaceName    = $definition->shortName . 'ServiceAsyncInterface';
        $parentIface  = '\\' . ltrim($definition->interfaceName, '\\');

        $methods = '';

        foreach ($definition->methods as $method) {
            if ($method->isVoid || $method->noAsync) {
                continue;
            }

            $params = implode(', ', array_map(
                fn($p) => ($p->nullable ? '?' : '') . $p->type . ' $' . $p->name,
                $method->parameters,
            ));

            $returnType = $method->returnType ?? 'mixed';
            $methods   .= "\n    /** @return Future<{$returnType}> */\n";
            $methods   .= "    public function {$method->name}Async({$params}): Future;\n";
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Monadial\\Nexus\\Runtime\\Async\\Future;

            // Generated — do not edit.
            interface {$ifaceName} extends {$parentIface}
            {
            {$methods}}
            PHP;
    }
}
```

**Step 4: Write and implement ProxyGenerator** (same pattern — creates proxy class implementing async interface):

```php
// packages/nexus-codegen/src/Generator/ProxyGenerator.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Generator;

use Monadial\Nexus\Codegen\Definition\ServiceDefinition;

final class ProxyGenerator
{
    public function generate(ServiceDefinition $definition): string
    {
        $ns          = $definition->outputNamespace;
        $proxyClass  = $definition->shortName . 'ServiceActorProxy';
        $asyncIface  = $definition->shortName . 'ServiceAsyncInterface';
        $timeoutNs   = (int) ($definition->timeout * 1_000_000_000);

        $syncMethods  = '';
        $asyncMethods = '';

        foreach ($definition->methods as $method) {
            $params     = implode(', ', array_map(fn($p) => ($p->nullable ? '?' : '') . $p->type . ' $' . $p->name, $method->parameters));
            $args       = implode(', ', array_map(fn($p) => 'new Message\\' . $method->pascalName . '(...)', []));
            $msgArgs    = implode(', ', array_map(fn($p) => '$' . $p->name, $method->parameters));
            $inputMsg   = "new Message\\{$method->pascalName}({$msgArgs})";
            $returnType = $method->returnType ?? 'void';

            if ($method->isVoid) {
                $syncMethods  .= "\n    public function {$method->name}({$params}): void\n    {\n        \$this->actorRef->tell({$inputMsg});\n    }\n";
            } else {
                $syncMethods  .= "\n    public function {$method->name}({$params}): {$returnType}\n    {\n        /** @var Message\\{$method->pascalName}Response \$r */\n        \$r = \$this->actorRef->ask({$inputMsg}, \$this->timeout)->await();\n        return \$r->result;\n    }\n";

                if (!$method->noAsync) {
                    $asyncMethods .= "\n    public function {$method->name}Async({$params}): Future\n    {\n        return \$this->actorRef->ask({$inputMsg}, \$this->timeout)->map(static fn(Message\\{$method->pascalName}Response \$r): {$returnType} => \$r->result);\n    }\n";
                }
            }
        }

        return <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns};

            use Monadial\\Nexus\\Core\\Actor\\ActorRef;
            use Monadial\\Nexus\\Runtime\\Async\\Future;
            use Monadial\\Nexus\\Runtime\\Duration;
            use {$ns}\\Message;

            // Generated — do not edit.
            final class {$proxyClass} implements {$asyncIface}
            {
                public function __construct(
                    private readonly ActorRef \$actorRef,
                    private readonly Duration \$timeout = new Duration({$timeoutNs}),
                ) {}
            {$syncMethods}{$asyncMethods}}
            PHP;
    }
}
```

**Step 5: Run all generator tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Generator/
```

Expected: PASS (all generator tests).

**Step 6: Commit**

```bash
git add packages/nexus-codegen/
git commit -m "feat(codegen): add AsyncInterfaceGenerator and ProxyGenerator"
```

---

## Task 8: Actorizer orchestrator — writes files to disk

**Files:**
- Create: `packages/nexus-codegen/src/Actorizer.php`
- Create: `packages/nexus-codegen/tests/Unit/ActorizerTest.php`

**Step 1: Write failing test**

```php
// packages/nexus-codegen/tests/Unit/ActorizerTest.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit;

use Monadial\Nexus\Codegen\Actorizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Actorizer::class)]
final class ActorizerTest extends TestCase
{
    #[Test]
    public function actorizes_fixture_service_and_writes_files(): void
    {
        $outputDir = sys_get_temp_dir() . '/nexus-codegen-test-' . uniqid();
        mkdir($outputDir, recursive: true);

        $actorizer = new Actorizer(outputBaseDir: $outputDir);
        $actorizer->actorize(__DIR__ . '/../Fixture/ProductService.php');

        $messageDir = $outputDir . '/Message';
        self::assertFileExists($messageDir . '/GetProduct.php');
        self::assertFileExists($messageDir . '/GetProductResponse.php');
        self::assertFileExists($messageDir . '/CreateProduct.php');
        self::assertFileExists($messageDir . '/CreateProductResponse.php');
        self::assertFileExists($messageDir . '/DeleteProduct.php');
        // void — no response
        self::assertFileDoesNotExist($messageDir . '/DeleteProductResponse.php');

        self::assertFileExists($outputDir . '/ProductServiceActor.php');
        self::assertFileExists($outputDir . '/ProductServiceAsyncInterface.php');
        self::assertFileExists($outputDir . '/ProductServiceActorProxy.php');

        // Cleanup
        exec("rm -rf {$outputDir}");
    }
}
```

**Step 2: Run to verify it fails**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/ActorizerTest.php
```

**Step 3: Implement Actorizer**

```php
// packages/nexus-codegen/src/Actorizer.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen;

use Monadial\Nexus\Codegen\Analyzer\ServiceAnalyzer;
use Monadial\Nexus\Codegen\Generator\ActorGenerator;
use Monadial\Nexus\Codegen\Generator\AsyncInterfaceGenerator;
use Monadial\Nexus\Codegen\Generator\MessageGenerator;
use Monadial\Nexus\Codegen\Generator\ProxyGenerator;

final class Actorizer
{
    private ServiceAnalyzer        $analyzer;
    private MessageGenerator       $messageGenerator;
    private ActorGenerator         $actorGenerator;
    private AsyncInterfaceGenerator $asyncInterfaceGenerator;
    private ProxyGenerator         $proxyGenerator;

    public function __construct(private readonly string $outputBaseDir = 'src')
    {
        $this->analyzer                = new ServiceAnalyzer();
        $this->messageGenerator        = new MessageGenerator();
        $this->actorGenerator          = new ActorGenerator();
        $this->asyncInterfaceGenerator = new AsyncInterfaceGenerator();
        $this->proxyGenerator          = new ProxyGenerator();
    }

    public function actorize(string $sourceFile): void
    {
        $definition = $this->analyzer->analyze($sourceFile);
        $outputDir  = $this->outputBaseDir . '/' . str_replace('\\', '/', $definition->shortName . 'Service');

        // Use the namespace-derived path but rooted at outputBaseDir
        $parts     = explode('\\', $definition->outputNamespace);
        $relative  = implode('/', array_slice($parts, 1)); // strip root namespace
        $outputDir = $this->outputBaseDir . '/' . $relative;
        $messageDir = $outputDir . '/Message';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        if (!is_dir($messageDir)) {
            mkdir($messageDir, 0755, true);
        }

        // Messages
        foreach ($definition->methods as $method) {
            $input = $this->messageGenerator->generateInput($definition->outputNamespace, $method);
            file_put_contents($messageDir . '/' . $method->pascalName . '.php', $input);

            $response = $this->messageGenerator->generateResponse($definition->outputNamespace, $method);

            if ($response !== null) {
                file_put_contents($messageDir . '/' . $method->pascalName . 'Response.php', $response);
            }
        }

        // Actor
        file_put_contents(
            $outputDir . '/' . $definition->shortName . 'ServiceActor.php',
            $this->actorGenerator->generate($definition),
        );

        // Async interface + proxy (only when async: true)
        if ($definition->async) {
            file_put_contents(
                $outputDir . '/' . $definition->shortName . 'ServiceAsyncInterface.php',
                $this->asyncInterfaceGenerator->generate($definition),
            );

            file_put_contents(
                $outputDir . '/' . $definition->shortName . 'ServiceActorProxy.php',
                $this->proxyGenerator->generate($definition),
            );
        }
    }
}
```

**Step 4: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/ActorizerTest.php
```

Expected: PASS.

**Step 5: Run full unit suite to check nothing broke**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

**Step 6: Commit**

```bash
git add packages/nexus-codegen/
git commit -m "feat(codegen): add Actorizer orchestrator — writes all generated files to disk"
```

---

## Task 9: Watch mode

**Files:**
- Create: `packages/nexus-codegen/src/Watcher/FileWatcher.php`
- Create: `packages/nexus-codegen/tests/Unit/Watcher/FileWatcherTest.php`

**Step 1: Write failing test**

```php
// packages/nexus-codegen/tests/Unit/Watcher/FileWatcherTest.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Tests\Unit\Watcher;

use Monadial\Nexus\Codegen\Watcher\FileWatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileWatcher::class)]
final class FileWatcherTest extends TestCase
{
    #[Test]
    public function detects_changed_file(): void
    {
        $file    = tempnam(sys_get_temp_dir(), 'nexus-watch-');
        $watcher = new FileWatcher([$file], intervalMs: 10);

        $changed = [];
        $watcher->onChange(function (string $path) use (&$changed): void {
            $changed[] = $path;
        });

        // Touch the file to change mtime
        sleep(1);
        touch($file);

        $watcher->tick();

        self::assertContains($file, $changed);
        unlink($file);
    }
}
```

**Step 2: Implement FileWatcher**

```php
// packages/nexus-codegen/src/Watcher/FileWatcher.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen\Watcher;

final class FileWatcher
{
    /** @var array<string, int> filepath → last mtime */
    private array $mtimes = [];

    /** @var callable(string): void */
    private $callback;

    /** @param string[] $files */
    public function __construct(
        private readonly array $files,
        private readonly int $intervalMs = 500,
    ) {
        foreach ($files as $file) {
            $this->mtimes[$file] = filemtime($file) ?: 0;
        }
    }

    /** @param callable(string): void $callback */
    public function onChange(callable $callback): void
    {
        $this->callback = $callback;
    }

    public function tick(): void
    {
        foreach ($this->files as $file) {
            $mtime = filemtime($file) ?: 0;

            if ($mtime > ($this->mtimes[$file] ?? 0)) {
                $this->mtimes[$file] = $mtime;
                ($this->callback)($file);
            }
        }
    }

    public function run(): never
    {
        while (true) {
            $this->tick();
            usleep($this->intervalMs * 1000);
        }
    }
}
```

**Step 3: Run tests**

```bash
docker compose exec php vendor/bin/phpunit packages/nexus-codegen/tests/Unit/Watcher/FileWatcherTest.php
```

Expected: PASS.

**Step 4: Commit**

```bash
git add packages/nexus-codegen/
git commit -m "feat(codegen): add FileWatcher for watch mode"
```

---

## Task 10: nexus-symfony-codegen package — compiler pass + console command

**Files:**
- Create: `packages/nexus-symfony-codegen/composer.json`
- Create: `packages/nexus-symfony-codegen/src/ActorizeCompilerPass.php`
- Create: `packages/nexus-symfony-codegen/src/ActorizeCommand.php`
- Create: `packages/nexus-symfony-codegen/src/NexusCodegenBundle.php`
- Modify: root `composer.json` — add autoload entries
- Modify: `phpunit.xml`, `deptrac.yaml`

**Step 1: Create `packages/nexus-symfony-codegen/composer.json`**

```json
{
    "name": "nexus-actors/symfony-codegen",
    "description": "Symfony bridge for nexus-codegen — compiler pass, console command, DI alias registration",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5",
        "nexus-actors/codegen": "dev-main",
        "symfony/dependency-injection": "^7.0",
        "symfony/console": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Codegen\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\Symfony\\Codegen\\Tests\\": "tests/"
        }
    }
}
```

**Step 2: Implement NexusCodegenBundle**

```php
// packages/nexus-symfony-codegen/src/NexusCodegenBundle.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Codegen;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class NexusCodegenBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new ActorizeCompilerPass());
    }
}
```

**Step 3: Implement ActorizeCompilerPass**

The compiler pass:
1. Scans all service definitions for `#[Actorize]`
2. Checks if generated files are stale (source newer than generated)
3. Re-runs `Actorizer::actorize()` if stale
4. Registers the proxy as alias for the interface

```php
// packages/nexus-symfony-codegen/src/ActorizeCompilerPass.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Codegen;

use Monadial\Nexus\Codegen\Actorizer;
use Monadial\Nexus\Codegen\Attribute\Actorize;
use Override;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ActorizeCompilerPass implements CompilerPassInterface
{
    #[Override]
    public function process(ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $actorizer  = new Actorizer(outputBaseDir: $projectDir . '/src/Generated/Actor');

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $class = $definition->getClass();

            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $attrs      = $reflection->getAttributes(Actorize::class);

            if ($attrs === []) {
                continue;
            }

            $sourceFile = $reflection->getFileName();

            if ($sourceFile === false) {
                continue;
            }

            // Regenerate if stale
            if ($this->isStale($sourceFile, $class, $projectDir)) {
                $actorizer->actorize($sourceFile);
            }

            // Register proxy as alias for the interface
            $interfaces = $reflection->getInterfaceNames();

            if (count($interfaces) !== 1) {
                continue;
            }

            $interface  = $interfaces[0];
            $shortName  = $this->deriveShortName($class);
            $proxyClass = 'App\\Generated\\Actor\\' . $shortName . '\\' . $shortName . 'ServiceActorProxy';

            if (class_exists($proxyClass)) {
                $container->setAlias($interface, new Alias($proxyClass, true));
            }
        }
    }

    private function isStale(string $sourceFile, string $class, string $projectDir): bool
    {
        $shortName  = $this->deriveShortName($class);
        $actorFile  = $projectDir . '/src/Generated/Actor/' . $shortName . '/' . $shortName . 'ServiceActor.php';

        if (!file_exists($actorFile)) {
            return true;
        }

        return filemtime($sourceFile) > filemtime($actorFile);
    }

    private function deriveShortName(string $fqcn): string
    {
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);

        return str_ends_with($short, 'Service')
            ? substr($short, 0, -strlen('Service'))
            : $short;
    }
}
```

**Step 4: Implement ActorizeCommand**

```php
// packages/nexus-symfony-codegen/src/ActorizeCommand.php
<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\Codegen;

use Monadial\Nexus\Codegen\Actorizer;
use Monadial\Nexus\Codegen\Watcher\FileWatcher;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'nexus:actorize', description: 'Generate actor infrastructure from #[Actorize]-annotated services')]
final class ActorizeCommand extends Command
{
    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Path to the service file to actorize')
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Watch for changes and regenerate automatically');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $actorizer = new Actorizer(outputBaseDir: $this->projectDir . '/src/Generated/Actor');

        $file = $input->getArgument('file');

        if ($file === null) {
            $io->error('Provide a file path or use --all (not yet implemented)');
            return Command::FAILURE;
        }

        if (!file_exists($file)) {
            $io->error("File not found: {$file}");
            return Command::FAILURE;
        }

        if ($input->getOption('watch')) {
            $io->info("Watching {$file} for changes. Press Ctrl+C to stop.");

            $watcher = new FileWatcher([$file]);
            $watcher->onChange(function (string $changed) use ($actorizer, $io): void {
                $actorizer->actorize($changed);
                $io->success("Regenerated: {$changed}");
            });

            $watcher->run(); // never returns
        }

        $actorizer->actorize($file);
        $io->success("Actorized: {$file}");

        return Command::SUCCESS;
    }
}
```

**Step 5: Add to root composer.json autoload**

```json
"Monadial\\Nexus\\Symfony\\Codegen\\": "packages/nexus-symfony-codegen/src/",
```

```bash
docker compose exec php composer install
```

**Step 6: Run full unit suite**

```bash
docker compose exec php vendor/bin/phpunit --testsuite=unit
```

Expected: all pass, no regressions.

**Step 7: Run psalm and phpcs**

```bash
make psalm && make phpcs
```

Fix any issues before committing.

**Step 8: Commit**

```bash
git add packages/nexus-symfony-codegen/ composer.json phpunit.xml deptrac.yaml
git commit -m "feat(symfony-codegen): add NexusCodegenBundle, ActorizeCompilerPass, ActorizeCommand"
```

---

## Task 11: Psalm + PHPCS clean, final commit

**Step 1: Run full suite**

```bash
make test-unit && make psalm && make phpcs
```

**Step 2: Fix any violations**

```bash
make phpcbf   # auto-fix PHPCS
make cs-fix   # auto-fix CS-Fixer
```

**Step 3: Final commit**

```bash
git add -A
git commit -m "fix(codegen): resolve psalm and phpcs violations"
```

---

## Done

All tasks complete. Run finishing-a-development-branch skill to create the PR.
