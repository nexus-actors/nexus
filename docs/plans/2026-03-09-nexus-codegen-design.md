# nexus-codegen Design

## Goal

Eliminate actor boilerplate by generating message classes, actor handlers, async interfaces, and proxy classes from annotated PHP service classes using AST analysis.

## Problem

Adopting the actor model in an existing codebase requires significant manual work: defining message classes for every method, writing actor handlers that delegate to services, wiring proxy classes that translate sync calls to `ask()`/`tell()`. For a service with 8 methods, this is ~15 files and ~300 lines of boilerplate before writing a single line of business logic.

## Solution

A standalone `nexus-codegen` package that reads annotated service classes via AST (`nikic/php-parser`), extracts method signatures with full type information, and generates all actor infrastructure as real PHP files. A Symfony bridge (`nexus-symfony-codegen`) adds a compiler pass for automatic staleness detection and a console command.

---

## Packages

### `nexus-codegen` (standalone, no Symfony dependency)

**Dependencies:** `nikic/php-parser`, `nette/php-generator`

Contains:
- Attributes: `#[Actorize]`, `#[Mutates]`, `#[Query]`, `#[NoAsync]`
- `Resettable` interface
- AST analyzer (`ServiceAnalyzer`)
- Code generators (message, actor, async interface, proxy)
- CLI entry point
- Filesystem watcher (watch mode)

### `nexus-symfony-codegen` (Symfony bridge)

**Dependencies:** `nexus-codegen`, `symfony/dependency-injection`

Contains:
- `ActorizeCompilerPass` — staleness check, auto-regeneration on `cache:warmup`
- `ActorizeCommand` — `nexus:actorize` console command with `--watch` flag
- Auto-registration of `ProductServiceActorProxy` as `ProductServiceInterface` alias in DI

---

## Attributes

### `#[Actorize]`

Applied to the concrete service class. The class **must** implement exactly one interface — codegen fails with a descriptive error if zero or multiple interfaces are found.

```php
#[Actorize(
    async: true,           // generate *Async() methods and ProductServiceAsyncInterface
    supervision: 'one-for-one', // SupervisionStrategy preset: one-for-one | all-for-one | backoff
    timeout: 5,            // default ask() timeout in seconds (int)
    reset: null,           // null = auto-detect Resettable; true = require reset(); false = never reset
    namespace: null,       // override output namespace; default: {AppNamespace}\Generated\Actor\{Name}
)]
```

### `#[Mutates]`

Applied to methods that mutate state. Affects void handling:
- `void + #[Mutates]` → `tell()` (fire-and-forget), no response message generated
- `void` without `#[Mutates]` → `tell()` (fire-and-forget), no response message generated
- Non-void + `#[Mutates]` → `ask()` + reply, response message generated

The distinction is reserved for Phase 2 (event sourcing) where `#[Mutates]` determines which methods emit events.

### `#[Query]`

Explicit query marker. Default for all methods that are not `#[Mutates]`. Optional — only needed to be explicit.

### `#[NoAsync]`

Excludes a method from async interface and proxy async generation even when `async: true` globally.

---

## Constraint: Interface Required

The annotated service must implement exactly one interface:

```php
// ✓ valid
#[Actorize]
final class ProductService implements ProductServiceInterface { ... }

// ✗ fails — no interface
#[Actorize]
final class ProductService { ... }

// ✗ fails — multiple interfaces (ambiguous)
#[Actorize]
final class ProductService implements ProductServiceInterface, AnotherInterface { ... }
```

Codegen reads method signatures from the **interface**, not the concrete class. This ensures the generated proxy implements exactly the public contract.

---

## Generated File Structure

For `App\Service\ProductService implements ProductServiceInterface`:

```
src/Generated/Actor/Product/
├── Message/
│   ├── GetProduct.php                   ← input (method args as readonly properties)
│   ├── GetProductResponse.php           ← output (return type as readonly property)
│   ├── CreateProduct.php
│   ├── CreateProductResponse.php
│   └── DeleteProduct.php                ← void: no response message
├── ProductServiceActor.php              ← ActorHandler wrapping ProductServiceInterface
├── ProductServiceAsyncInterface.php     ← extends ProductServiceInterface (async: true only)
└── ProductServiceActorProxy.php         ← implements ProductServiceAsyncInterface
```

**Naming conventions:**
- Input messages: `{MethodPascalCase}` → `GetProduct`, `CreateProduct`, `DeleteProduct`
- Response messages: `{MethodPascalCase}Response` → `GetProductResponse`
- Default output namespace: `{RootNamespace}\Generated\Actor\{ServiceShortName}\`

---

## Generated Code

### Input message

```php
// Generated — do not edit
readonly class GetProduct
{
    public function __construct(public string $id) {}
}
```

### Response message

```php
// Generated — do not edit
readonly class GetProductResponse
{
    public function __construct(public Product $product) {}
}
```

### Actor

```php
// Generated — do not edit
final class ProductServiceActor implements ActorHandler
{
    public function __construct(private readonly ProductServiceInterface $service) {}

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        return match (true) {
            $message instanceof GetProduct    => $this->handleGetProduct($ctx, $message),
            $message instanceof CreateProduct => $this->handleCreateProduct($ctx, $message),
            $message instanceof DeleteProduct => $this->handleDeleteProduct($ctx, $message),
            default                           => Behavior::unhandled(),
        };
    }

    private function handleGetProduct(ActorContext $ctx, GetProduct $msg): Behavior
    {
        try {
            $ctx->reply(new GetProductResponse($this->service->getProduct($msg->id)));
        } finally {
            $this->resetIfNeeded();
        }

        return Behavior::same();
    }

    private function handleCreateProduct(ActorContext $ctx, CreateProduct $msg): Behavior
    {
        try {
            $ctx->reply(new CreateProductResponse(
                $this->service->createProduct($msg->name, $msg->price)
            ));
        } finally {
            $this->resetIfNeeded();
        }

        return Behavior::same();
    }

    private function handleDeleteProduct(ActorContext $ctx, DeleteProduct $msg): Behavior
    {
        try {
            $this->service->deleteProduct($msg->id); // void → no reply
        } finally {
            $this->resetIfNeeded();
        }

        return Behavior::same();
    }

    private function resetIfNeeded(): void
    {
        if ($this->service instanceof Resettable) {
            $this->service->reset();
        }
    }
}
```

### Async interface

```php
// Generated — do not edit
interface ProductServiceAsyncInterface extends ProductServiceInterface
{
    /** @return Future<Product> */
    public function getProductAsync(string $id): Future;

    /** @return Future<Product> */
    public function createProductAsync(string $name, float $price): Future;

    // deleteProduct is void — fire-and-forget, no async variant
}
```

### Proxy

```php
// Generated — do not edit
final class ProductServiceActorProxy implements ProductServiceAsyncInterface
{
    public function __construct(
        private readonly ActorRef $actorRef,
        private readonly Duration $timeout,
    ) {}

    // --- Sync (implements ProductServiceInterface transparently) ---

    public function getProduct(string $id): Product
    {
        /** @var GetProductResponse $r */
        $r = $this->actorRef->ask(new GetProduct($id), $this->timeout)->await();

        return $r->product;
    }

    public function createProduct(string $name, float $price): Product
    {
        /** @var CreateProductResponse $r */
        $r = $this->actorRef->ask(new CreateProduct($name, $price), $this->timeout)->await();

        return $r->product;
    }

    public function deleteProduct(string $id): void
    {
        $this->actorRef->tell(new DeleteProduct($id));
    }

    // --- Async ---

    public function getProductAsync(string $id): Future
    {
        return $this->actorRef
            ->ask(new GetProduct($id), $this->timeout)
            ->map(static fn(GetProductResponse $r): Product => $r->product);
    }

    public function createProductAsync(string $name, float $price): Future
    {
        return $this->actorRef
            ->ask(new CreateProduct($name, $price), $this->timeout)
            ->map(static fn(CreateProductResponse $r): Product => $r->product);
    }
}
```

---

## Resettable Interface

Defined in `nexus-codegen`, no external dependencies:

```php
interface Resettable
{
    public function reset(): void;
}
```

The Symfony bridge maps `Symfony\Contracts\Service\ResetInterface` to `Resettable` automatically — services that already implement Symfony's reset contract are handled without any code changes.

**`reset` flag behaviour:**
- `reset: null` (default) — call `reset()` if service implements `Resettable`, skip otherwise
- `reset: true` — always call `reset()`, fail at compile time if service does not implement `Resettable`
- `reset: false` — never call `reset()`, skip the `resetIfNeeded()` call entirely (cleaner generated code)

---

## Regeneration

### CLI

```bash
# Generate for a single class
bin/console nexus:actorize 'App\Service\ProductService'

# Generate for all #[Actorize] classes in the project
bin/console nexus:actorize --all

# Watch mode — regenerate on source file change
bin/console nexus:actorize --watch
bin/console nexus:actorize --watch --all
```

Existing generated files are **overwritten**. There are no user-editable generated files (event sourcing state is Phase 2).

### Compiler pass (Symfony)

On every `cache:clear` / `cache:warmup`, `ActorizeCompilerPass` compares the mtime of each annotated source file against its generated files. If the source is newer, regeneration runs automatically. No manual command needed in CI or deployment.

### Watch mode

Polls the filesystem every 500ms for changes to `#[Actorize]`-annotated source files. On change, regenerates all files for that service and prints a summary. Suitable for `docker compose up` dev workflow.

---

## Type Resolution

The AST analyzer resolves types using nikic/php-parser's name resolver. Supported in Phase 1:

| PHP type | Generated property type |
|---|---|
| `string`, `int`, `float`, `bool` | Same scalar |
| `?string`, `?int` etc. | Nullable scalar |
| Named class `Product` | Fully-qualified `Product` |
| `?Product` | `?Product` |
| `array` | `array` (untyped) |
| `void` | No response message generated |

Phase 2 (future): `array<K, V>`, `Collection<T>`, union types, intersection types.

---

## Error Handling

Exceptions thrown by the service inside a handler are caught and propagated back to the `ask()` caller as `ActorHandlerException`. The actor does not crash — it remains alive to handle subsequent messages:

```php
try {
    $ctx->reply(new GetProductResponse($this->service->getProduct($msg->id)));
} catch (\Throwable $e) {
    $ctx->reply(new ActorHandlerException($e)); // ask() caller receives the exception
} finally {
    $this->resetIfNeeded();
}
```

---

## Symfony DI Integration

`nexus-symfony-codegen` registers the proxy as the service interface alias automatically:

```yaml
# Auto-generated in container (no manual config needed)
App\Service\ProductServiceInterface:
    alias: App\Generated\Actor\Product\ProductServiceActorProxy
```

Existing code injecting `ProductServiceInterface` transparently receives the actor-backed proxy.

---

## Phase 2 (not in scope now)

- Event sourcing overlay (`eventSourced: true`) — events carry return values, state rebuilt from replay
- Generic type resolution (`array<K,V>`, `Collection<T>`)
- Multiple interface support with explicit selection
- `#[Actorize(persistenceId: 'products')]` for ES persistence identity
