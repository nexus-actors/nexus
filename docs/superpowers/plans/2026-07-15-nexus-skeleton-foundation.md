# Nexus Skeleton Rework — Plan 1: Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A minimal, Symfony-style `nexus-skeleton` that boots the `ActorSystem` through a `symfony/dependency-injection` autowiring container and auto-spawns every `#[AsActor]`-attributed handler — no `bootstrap.php`, no installer.

**Architecture:** A framework-agnostic `#[AsActor(name)]` attribute + `ActorRegistry` live in `nexus-app`. The skeleton owns the symfony/di glue: a thin `Kernel` registers the attribute for autoconfiguration (tagging handlers `nexus.actor`, non-shared), a compiler pass folds the tagged services into the `ActorRegistry`, and `boot()` spawns each via `Props::fromContainer`.

**Tech Stack:** PHP 8.5, `symfony/dependency-injection` (PHP config, autowire+autoconfigure, PhpDumper), `symfony/console`, Nexus core + runtime-fiber. Later plans add `nette/php-generator`.

## Global Constraints

- PHP: `>=8.5.7`.
- All commands run in Docker: `docker compose exec -T php …` / `php-fiber`. Never local php/composer.
- Code style: PER-CS2.0 + Slevomat; arrays with string keys sorted alphabetically; trailing commas in multiline; `final` classes; `readonly` value objects; ordered imports (class, function, const).
- Never add `Co-Authored-By: Claude` to commits.
- `nexus-app` must stay `Core only` in deptrac EXCEPT this plan adds a dependency on nothing new (the attribute + registry are pure PHP; the symfony/di glue lives in the skeleton, which is a `type: project` template outside deptrac/psalm/phpcs scope).
- The skeleton (`packages/nexus-skeleton`) is a `type: project` template: it is NOT in the root `composer.json` autoload, `phpunit.xml`, `psalm.xml`, `phpcs.xml`, or `.php-cs-fixer.dist.php` file lists. Its own tests run inside the container against the monorepo autoload.

---

## File Structure

**`nexus-app` (library — new files):**
- `packages/nexus-app/src/AsActor.php` — the `#[AsActor(name)]` attribute.
- `packages/nexus-app/src/ActorRegistry.php` — name → handler class-string map.
- `packages/nexus-app/tests/Unit/AsActorTest.php`, `ActorRegistryTest.php`.

**`nexus-skeleton` (template — replaces the old skeleton):**
- `packages/nexus-skeleton/composer.json` — minimal deps.
- `packages/nexus-skeleton/.env`
- `packages/nexus-skeleton/config/services.php` — autowire+autoconfigure `src/`.
- `packages/nexus-skeleton/config/packages/runtime.php` — returns the `Runtime`.
- `packages/nexus-skeleton/src/Kernel.php` — build container, boot ActorSystem.
- `packages/nexus-skeleton/src/DependencyInjection/AsActorPass.php` — compiler pass.
- `packages/nexus-skeleton/src/Command/RunCommand.php` — `nexus:run`.
- `packages/nexus-skeleton/src/Actor/GreeterActor.php` — one sample `#[AsActor]`.
- `packages/nexus-skeleton/src/Message/Greet.php` — sample message.
- `packages/nexus-skeleton/bin/console` — symfony/console entrypoint.
- `packages/nexus-skeleton/tests/KernelBootTest.php` — integration test.

**Deleted from the old skeleton (Task 6):** `installer/`, `templates/`, `bootstrap.php`, `public/index.php` (re-added by `enable:http` in Plan 3), the old `src/*`, `docker-compose.*` extras kept.

---

### Task 1: `#[AsActor]` attribute + `ActorRegistry` (nexus-app)

**Files:**
- Create: `packages/nexus-app/src/AsActor.php`
- Create: `packages/nexus-app/src/ActorRegistry.php`
- Test: `packages/nexus-app/tests/Unit/AsActorTest.php`, `packages/nexus-app/tests/Unit/ActorRegistryTest.php`

**Interfaces:**
- Produces: `Monadial\Nexus\App\AsActor` with `public readonly string $name`. `Monadial\Nexus\App\ActorRegistry` with `register(string $name, string $class): void` and `all(): array<string,class-string>`.

- [ ] **Step 1: Write failing tests**

```php
// packages/nexus-app/tests/Unit/AsActorTest.php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\App\Tests\Unit;

use Monadial\Nexus\App\AsActor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AsActor::class)]
final class AsActorTest extends TestCase
{
    #[Test]
    public function exposesTheActorName(): void
    {
        self::assertSame('greeter', new AsActor('greeter')->name);
    }

    #[Test]
    public function isReadableViaReflectionAsAClassAttribute(): void
    {
        $attrs = (new \ReflectionClass(FixtureAttributedActor::class))->getAttributes(AsActor::class);
        self::assertCount(1, $attrs);
        self::assertSame('fixture', $attrs[0]->newInstance()->name);
    }
}

#[AsActor('fixture')]
final class FixtureAttributedActor {}
```

```php
// packages/nexus-app/tests/Unit/ActorRegistryTest.php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\App\Tests\Unit;

use Monadial\Nexus\App\ActorRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActorRegistry::class)]
final class ActorRegistryTest extends TestCase
{
    #[Test]
    public function registersAndReturnsNameToClassMap(): void
    {
        $registry = new ActorRegistry();
        $registry->register('greeter', 'App\\Actor\\GreeterActor');

        self::assertSame(['greeter' => 'App\\Actor\\GreeterActor'], $registry->all());
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-app/tests/Unit/AsActorTest.php packages/nexus-app/tests/Unit/ActorRegistryTest.php`
Expected: FAIL — `Class "Monadial\Nexus\App\AsActor" not found`.

- [ ] **Step 3: Implement**

```php
// packages/nexus-app/src/AsActor.php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\App;

use Attribute;

/**
 * @psalm-api
 *
 * Marks an ActorHandler for auto-registration + auto-spawn under $name. The skeleton's
 * Kernel registers this attribute for symfony/di autoconfiguration; a compiler pass folds
 * every attributed handler into the ActorRegistry.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsActor
{
    public function __construct(public string $name) {}
}
```

```php
// packages/nexus-app/src/ActorRegistry.php
<?php
declare(strict_types=1);
namespace Monadial\Nexus\App;

/**
 * @psalm-api
 *
 * Name -> ActorHandler class-string map, populated by the skeleton's AsActorPass from every
 * #[AsActor]-tagged service, then read by the Kernel to spawn each actor at boot.
 */
final class ActorRegistry
{
    /** @var array<string, class-string> */
    private array $actors = [];

    /**
     * @param class-string $class
     */
    public function register(string $name, string $class): void
    {
        $this->actors[$name] = $class;
    }

    /**
     * @return array<string, class-string>
     */
    public function all(): array
    {
        return $this->actors;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T php-fiber vendor/bin/phpunit packages/nexus-app/tests/Unit/AsActorTest.php packages/nexus-app/tests/Unit/ActorRegistryTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Lint the new files**

Run: `docker compose exec -T php vendor/bin/phpcs -q packages/nexus-app/src/AsActor.php packages/nexus-app/src/ActorRegistry.php && docker compose exec -T php vendor/bin/psalm --no-progress packages/nexus-app/src`
Expected: clean, No errors.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-app/src/AsActor.php packages/nexus-app/src/ActorRegistry.php packages/nexus-app/tests/Unit/AsActorTest.php packages/nexus-app/tests/Unit/ActorRegistryTest.php
git commit -m "feat(app): #[AsActor] attribute + ActorRegistry for auto-spawn"
```

---

### Task 2: Skeleton scaffolding (composer + config + sample actor)

**Files:**
- Modify (replace): `packages/nexus-skeleton/composer.json`
- Create: `packages/nexus-skeleton/.env`
- Create: `packages/nexus-skeleton/config/services.php`
- Create: `packages/nexus-skeleton/config/packages/runtime.php`
- Create: `packages/nexus-skeleton/src/Message/Greet.php`
- Create: `packages/nexus-skeleton/src/Actor/GreeterActor.php`

**Interfaces:**
- Produces: PSR-4 `App\` → `src/`. `config/packages/runtime.php` returns a `Monadial\Nexus\Runtime\Runtime\Runtime`. `App\Actor\GreeterActor` (`#[AsActor('greeter')]`, `ActorHandler`) records handled `App\Message\Greet` names on an injected `App\Support\Recorder` (added here) so the Task 5 test can assert delivery.

- [ ] **Step 1: Write the composer.json**

```json
{
    "name": "nexus-actors/skeleton",
    "description": "Minimal skeleton for building applications on the Nexus actor system (PHP 8.5+).",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/app": "^0.1",
        "nexus-actors/core": "^0.1",
        "nexus-actors/runtime-fiber": "^0.1",
        "symfony/config": "^7.0",
        "symfony/console": "^7.0",
        "symfony/dependency-injection": "^7.0",
        "symfony/dotenv": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": { "App\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "App\\Tests\\": "tests/" }
    },
    "config": { "sort-packages": true },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

> Note: `symfony/*` version floors here are the skeleton's own (a generated project may use Symfony 7); the monorepo container has Symfony 8, which satisfies the tests run against the monorepo autoload.

- [ ] **Step 2: Create config + sample actor + recorder**

```php
// packages/nexus-skeleton/config/services.php
<?php
declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $c): void {
    $services = $c->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('App\\', __DIR__ . '/../src/')
        ->exclude([__DIR__ . '/../src/Kernel.php']);
};
```

```php
// packages/nexus-skeleton/config/packages/runtime.php
<?php
declare(strict_types=1);

use Monadial\Nexus\Runtime\Fiber\FiberRuntime;

return static fn (): FiberRuntime => new FiberRuntime();
```

```php
// packages/nexus-skeleton/src/Support/Recorder.php
<?php
declare(strict_types=1);
namespace App\Support;

final class Recorder
{
    /** @var list<string> */
    public array $greeted = [];
}
```

```php
// packages/nexus-skeleton/src/Message/Greet.php
<?php
declare(strict_types=1);
namespace App\Message;

final readonly class Greet
{
    public function __construct(public string $name) {}
}
```

```php
// packages/nexus-skeleton/src/Actor/GreeterActor.php
<?php
declare(strict_types=1);
namespace App\Actor;

use App\Message\Greet;
use App\Support\Recorder;
use Monadial\Nexus\App\AsActor;
use Monadial\Nexus\Core\Actor\ActorContext;
use Monadial\Nexus\Core\Actor\ActorHandler;
use Monadial\Nexus\Core\Actor\Behavior;

#[AsActor('greeter')]
final class GreeterActor implements ActorHandler
{
    public function __construct(private readonly Recorder $recorder) {}

    public function handle(ActorContext $ctx, object $message): Behavior
    {
        if ($message instanceof Greet) {
            $this->recorder->greeted[] = $message->name;
        }

        return Behavior::same();
    }
}
```

```dotenv
# packages/nexus-skeleton/.env
APP_ENV=dev
APP_NAME=my-app
```

- [ ] **Step 3: Commit (no test yet — wired up in Task 5)**

```bash
git add packages/nexus-skeleton/composer.json packages/nexus-skeleton/.env packages/nexus-skeleton/config packages/nexus-skeleton/src/Support packages/nexus-skeleton/src/Message packages/nexus-skeleton/src/Actor
git commit -m "feat(skeleton): minimal composer + config + sample #[AsActor] greeter"
```

---

### Task 3: `AsActorPass` compiler pass

**Files:**
- Create: `packages/nexus-skeleton/src/DependencyInjection/AsActorPass.php`
- Test: `packages/nexus-skeleton/tests/AsActorPassTest.php`

**Interfaces:**
- Consumes: `Monadial\Nexus\App\ActorRegistry`, `Monadial\Nexus\App\AsActor`.
- Produces: `App\DependencyInjection\AsActorPass implements CompilerPassInterface`. Reads services tagged `nexus.actor` (`['name' => …]`) and appends `register(name, id)` calls to the `ActorRegistry` definition.

- [ ] **Step 1: Write failing test**

```php
// packages/nexus-skeleton/tests/AsActorPassTest.php
<?php
declare(strict_types=1);
namespace App\Tests;

use App\DependencyInjection\AsActorPass;
use Monadial\Nexus\App\ActorRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class AsActorPassTest extends TestCase
{
    #[Test]
    public function foldsTaggedServicesIntoTheRegistry(): void
    {
        $c = new ContainerBuilder();
        $c->register(ActorRegistry::class, ActorRegistry::class)->setPublic(true);
        $c->register('App\\Actor\\GreeterActor', 'App\\Actor\\GreeterActor')
            ->addTag('nexus.actor', ['name' => 'greeter'])
            ->setPublic(true)
            ->setShared(false);

        (new AsActorPass())->process($c);
        $c->compile();

        self::assertSame(
            ['greeter' => 'App\\Actor\\GreeterActor'],
            $c->get(ActorRegistry::class)->all(),
        );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `docker compose exec -T php bash -lc 'cd /app/packages/nexus-skeleton && composer install --no-interaction >/dev/null 2>&1; vendor/bin/phpunit tests/AsActorPassTest.php'`
Expected: FAIL — `Class "App\DependencyInjection\AsActorPass" not found`.

- [ ] **Step 3: Implement**

```php
// packages/nexus-skeleton/src/DependencyInjection/AsActorPass.php
<?php
declare(strict_types=1);
namespace App\DependencyInjection;

use Monadial\Nexus\App\ActorRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AsActorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ActorRegistry::class)) {
            return;
        }

        $registry = $container->findDefinition(ActorRegistry::class);

        foreach ($container->findTaggedServiceIds('nexus.actor') as $id => $tags) {
            foreach ($tags as $tag) {
                $registry->addMethodCall('register', [(string) $tag['name'], $id]);
            }
        }
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `docker compose exec -T php bash -lc 'cd /app/packages/nexus-skeleton && vendor/bin/phpunit tests/AsActorPassTest.php'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-skeleton/src/DependencyInjection/AsActorPass.php packages/nexus-skeleton/tests/AsActorPassTest.php
git commit -m "feat(skeleton): AsActorPass folds #[AsActor] services into ActorRegistry"
```

---

### Task 4: `Kernel` — build container, boot ActorSystem

**Files:**
- Create: `packages/nexus-skeleton/src/Kernel.php`

**Interfaces:**
- Consumes: `App\DependencyInjection\AsActorPass`, `Monadial\Nexus\App\AsActor`, `ActorRegistry`, `config/services.php`, `config/packages/runtime.php`.
- Produces: `App\Kernel` with `__construct(string $projectDir, string $appName)`, `container(): Container` (compiled), `boot(): ActorSystem` (creates the system, spawns every registered actor, returns it un-run), `run(): void` (`boot()` then `$system->run()`).

- [ ] **Step 1: Implement the Kernel** (tested via the Task 5 integration test)

```php
// packages/nexus-skeleton/src/Kernel.php
<?php
declare(strict_types=1);
namespace App;

use App\DependencyInjection\AsActorPass;
use Monadial\Nexus\App\ActorRegistry;
use Monadial\Nexus\App\AsActor;
use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Core\Actor\Props;
use Monadial\Nexus\Runtime\Runtime\Runtime;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Config\FileLocator;

final class Kernel
{
    private ?ContainerInterface $container = null;

    public function __construct(
        private readonly string $projectDir,
        private readonly string $appName = 'my-app',
    ) {}

    public function container(): ContainerInterface
    {
        return $this->container ??= $this->buildContainer();
    }

    public function boot(): ActorSystem
    {
        $container = $this->container();

        /** @var ActorRegistry $registry */
        $registry = $container->get(ActorRegistry::class);
        /** @var Runtime $runtime */
        $runtime = $container->get('nexus.runtime');

        $system = ActorSystem::create($this->appName, $runtime);

        foreach ($registry->all() as $name => $class) {
            $system->spawn(Props::fromContainer($container, $class), $name);
        }

        return $system;
    }

    public function run(): void
    {
        $this->boot()->run();
    }

    private function buildContainer(): ContainerInterface
    {
        $container = new ContainerBuilder();

        // ActorRegistry is a public, shared service the Kernel reads after compile.
        $container->register(ActorRegistry::class, ActorRegistry::class)->setPublic(true);

        // Runtime comes from config/packages/runtime.php (a factory returning a Runtime).
        $runtimeFactory = require $this->projectDir . '/config/packages/runtime.php';
        $container->register('nexus.runtime', Runtime::class)
            ->setFactory($runtimeFactory)
            ->setPublic(true);

        // Autoconfigure: every #[AsActor] handler is tagged and made non-shared (fresh per spawn).
        $container->registerAttributeForAutoconfiguration(
            AsActor::class,
            static function (ChildDefinition $definition, AsActor $attribute): void {
                $definition->addTag('nexus.actor', ['name' => $attribute->name]);
                $definition->setShared(false);
                $definition->setPublic(true);
            },
        );

        $loader = new PhpFileLoader($container, new FileLocator($this->projectDir . '/config'));
        $loader->load('services.php');

        $container->addCompilerPass(new AsActorPass());
        $container->compile();

        return $container;
    }
}
```

> Note: the runtime factory returns a concrete `Runtime`; the `Runtime::class` id is a placeholder class for the definition — the factory result is what `get()` returns. `Props::fromContainer` fetches each non-shared handler fresh per spawn.

- [ ] **Step 2: Lint**

Run: `docker compose exec -T php bash -lc 'cd /app/packages/nexus-skeleton && vendor/bin/phpcs -q src/Kernel.php' || true` (skeleton is outside the monorepo phpcs config; run its own if present, otherwise visual check for PER-CS)
Expected: no PER-CS violations in the new file.

- [ ] **Step 3: Commit**

```bash
git add packages/nexus-skeleton/src/Kernel.php
git commit -m "feat(skeleton): thin Kernel builds the container and boots the ActorSystem"
```

---

### Task 5: Integration test — Kernel boots and delivers to an `#[AsActor]`

**Files:**
- Create: `packages/nexus-skeleton/tests/KernelBootTest.php`

**Interfaces:**
- Consumes: `App\Kernel`, `App\Support\Recorder`, `App\Message\Greet`, `App\Actor\GreeterActor`.

- [ ] **Step 1: Write the integration test**

```php
// packages/nexus-skeleton/tests/KernelBootTest.php
<?php
declare(strict_types=1);
namespace App\Tests;

use App\Kernel;
use App\Message\Greet;
use App\Support\Recorder;
use Monadial\Nexus\Runtime\Duration;
use Monadial\Nexus\Runtime\Fiber\FiberRuntime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KernelBootTest extends TestCase
{
    #[Test]
    public function bootsTheContainerAndAutoSpawnsAttributedActors(): void
    {
        $kernel = new Kernel(\dirname(__DIR__), 'test-app');
        $system = $kernel->boot();

        $ref = $system->child('greeter')->getOrElse(fn () => self::fail('greeter actor was not spawned'));

        /** @var FiberRuntime $runtime */
        $runtime = $kernel->container()->get('nexus.runtime');
        $runtime->scheduleOnce(Duration::millis(20), static fn () => $ref->tell(new Greet('world')));
        $runtime->scheduleOnce(Duration::millis(300), static fn () => $system->shutdown(Duration::seconds(1)));
        $system->run();

        /** @var Recorder $recorder */
        $recorder = $kernel->container()->get(Recorder::class);
        self::assertSame(['world'], $recorder->greeted);
    }
}
```

> If `ActorSystem::child()` does not return an `Option`, adjust to the actual accessor (`$system->child('greeter')`), keeping the assertion that the actor exists. Verify against `packages/nexus-core/src/Actor/ActorSystem.php` before running.

- [ ] **Step 2: Make `Recorder` public in the container** so the test can read it. Edit `config/services.php` to add, after the `load(...)`:

```php
    $services->set(\App\Support\Recorder::class)->public();
```

- [ ] **Step 3: Run the integration test**

Run: `docker compose exec -T php bash -lc 'cd /app/packages/nexus-skeleton && vendor/bin/phpunit tests/KernelBootTest.php'`
Expected: PASS — the greeter actor is auto-spawned and records `['world']`.

- [ ] **Step 4: Commit**

```bash
git add packages/nexus-skeleton/tests/KernelBootTest.php packages/nexus-skeleton/config/services.php
git commit -m "test(skeleton): Kernel boots and delivers to an #[AsActor] handler"
```

---

### Task 6: `bin/console` + `nexus:run`, and remove old skeleton files

**Files:**
- Create: `packages/nexus-skeleton/bin/console`
- Create: `packages/nexus-skeleton/src/Command/RunCommand.php`
- Delete: `packages/nexus-skeleton/installer/`, `packages/nexus-skeleton/templates/`, `packages/nexus-skeleton/bootstrap.php`, `packages/nexus-skeleton/public/index.php`, old `src/Http`, `src/Persistence`, `src/Message/Ping.php`, `src/Message/Pong.php`, `src/Actor/ExampleActor.php`, `tests/ExampleActorTest.php`.

**Interfaces:**
- Produces: `App\Command\RunCommand` (`#[AsCommand('nexus:run')]`) that builds a `Kernel` from the project dir and calls `run()`.

- [ ] **Step 1: Implement the run command + console entrypoint**

```php
// packages/nexus-skeleton/src/Command/RunCommand.php
<?php
declare(strict_types=1);
namespace App\Command;

use App\Kernel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('nexus:run', 'Boot the actor system and run until shutdown')]
final class RunCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Booting Nexus actor system…</info>');
        new Kernel(\dirname(__DIR__, 2), (string) (getenv('APP_NAME') ?: 'my-app'))->run();

        return Command::SUCCESS;
    }
}
```

```php
#!/usr/bin/env php
<?php
// packages/nexus-skeleton/bin/console
declare(strict_types=1);

use App\Command\RunCommand;
use Symfony\Component\Console\Application;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new Application('Nexus');
$app->add(new RunCommand());

// Dev-only: register the maker commands when the dev package is installed (Plan 2 adds them).
if (class_exists(\Nexus\Maker\MakerCommands::class)) {
    foreach (\Nexus\Maker\MakerCommands::all(dirname(__DIR__)) as $command) {
        $app->add($command);
    }
}

$app->run();
```

- [ ] **Step 2: Remove obsolete skeleton files**

```bash
git rm -r packages/nexus-skeleton/installer packages/nexus-skeleton/templates \
  packages/nexus-skeleton/bootstrap.php packages/nexus-skeleton/public/index.php \
  packages/nexus-skeleton/src/Http packages/nexus-skeleton/src/Persistence \
  packages/nexus-skeleton/src/Message/Ping.php packages/nexus-skeleton/src/Message/Pong.php \
  packages/nexus-skeleton/src/Actor/ExampleActor.php packages/nexus-skeleton/tests/ExampleActorTest.php
```

- [ ] **Step 3: Smoke-test the console boots (it will run until the 1s no-op; use a timeout)**

Run: `docker compose exec -T php bash -lc 'cd /app/packages/nexus-skeleton && timeout 3 php bin/console nexus:run; echo "exit=$?"'`
Expected: prints "Booting Nexus actor system…" then the system runs (idle) until `timeout` kills it (`exit=124`) — proves the console + Kernel wire up and boot without error.

- [ ] **Step 4: Re-run the full skeleton test suite**

Run: `docker compose exec -T php bash -lc 'cd /app/packages/nexus-skeleton && vendor/bin/phpunit'`
Expected: PASS (AsActorPassTest + KernelBootTest).

- [ ] **Step 5: Commit**

```bash
git add packages/nexus-skeleton/bin/console packages/nexus-skeleton/src/Command/RunCommand.php
git commit -m "feat(skeleton): bin/console + nexus:run; remove installer/templates/bootstrap.php"
```

---

## Self-Review

**Spec coverage (Plan 1 slice):**
- ✅ `#[AsActor]` in nexus-app (Task 1) · ✅ ActorRegistry (Task 1) · ✅ minimal Symfony layout: composer/config/src (Task 2) · ✅ compiler pass (Task 3) · ✅ thin Kernel + container + boot (Task 4) · ✅ integration test promoting the spike (Task 5) · ✅ bin/console + removal of installer/templates/bootstrap.php (Task 6).
- Deferred to later plans (correctly out of Plan 1 scope): `make:*`/`enable:*` (Plans 2–3), website removal + docs + split wiring (Plan 4), `public/index.php` (re-added by `enable:http` in Plan 3).

**Placeholders:** none — every step ships real code or an exact command.

**Type consistency:** `AsActor::$name`, `ActorRegistry::register(name,class)`/`all()`, the `nexus.actor` tag `['name'=>…]`, `nexus.runtime` service id, and `Props::fromContainer($container,$class)` are used consistently across Tasks 1–6.

**Open verification (flagged inline, must confirm against source during execution):**
1. `ActorSystem::child('greeter')` return shape (Option vs ref vs null) — Task 5 Step 1 note.
2. `Runtime` interface FQN is `Monadial\Nexus\Runtime\Runtime\Runtime` (per the cluster code `use`); confirm before Task 4.
3. That registering a service under `'nexus.runtime'` with a `setFactory()` returning a `FiberRuntime` and `get()`-ing it returns that instance (proven pattern; confirm no autowiring conflict on the placeholder `Runtime::class` type).
