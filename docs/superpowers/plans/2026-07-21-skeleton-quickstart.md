# Skeleton Quickstart Experience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `composer create-project nexus-actors/skeleton` a real, delightful first-run experience — interactive CLI setup wizard, `make:actor`/`make:message` generators — and replace the landing's web Bootstrap wizard with a `/quickstart` page plus a homepage cluster showcase.

**Architecture:** The skeleton gains a `nexus:setup` Symfony Console command triggered by `post-create-project-cmd`, driven by a static recipe catalog (module → packages + config template + doc link). Generators live in a new require-dev package `nexus-actors/maker` exposing `Nexus\Maker\MakerCommands::all()` — the hook `bin/console` already calls. Landing changes are static Astro (no new React islands).

**Tech Stack:** PHP 8.5, Symfony Console/DI 7, PHPUnit 13, Astro (landing), GitHub Actions.

## Global Constraints

- All PHP tooling runs through Docker in the monorepo (`docker compose exec php …`) — EXCEPT the skeleton/maker packages, which are standalone composer projects; their tests run wherever PHP 8.5 + composer exist (CI job uses setup-php).
- PER-CS2.0 + Slevomat: string-keyed arrays sorted alphabetically, multi-line ternaries, blank line before control structures, `final` classes, `readonly` value objects, ordered imports, trailing commas.
- Experimental modules must be labeled with the StabilityMatrix wording: "experimental, not production-ready; APIs may change".
- The documented install command is `composer create-project nexus-actors/skeleton my-app --stability=dev` (release gate: internal deps are `dev-main`).
- Never add `Co-Authored-By: Claude` to commits.
- Landing pages: bullets after a marker span must wrap content in a single `<span>` (see 2026-07 bullet-layout fix).

---

### Task 1: Break the meta-package name collision + split `observability-serialization`

**Files:**
- Modify: `composer.json:2`
- Modify: `.github/workflows/split.yml` (matrix)

**Interfaces:**
- Produces: root composer package renamed `nexus-actors/monorepo`; split matrix contains `nexus-observability-serialization`.

- [ ] **Step 1: Verify nothing keys on the root composer name**

Run: `grep -rn '"nexus-actors/nexus"' --include='*.json' --include='*.yml' --include='*.php' --include='Makefile' . | grep -v node_modules | grep -v packages/nexus/`
Expected: only `composer.json:2` (the lychee-links.yml hit is a URL pattern, not the composer name).

- [ ] **Step 2: Rename the root package**

In `composer.json` change line 2:

```json
    "name": "nexus-actors/monorepo",
```

- [ ] **Step 3: Add the missing split entry**

In `.github/workflows/split.yml`, the matrix is alphabetical by `local`. Insert after the `nexus-observability-persistence` entry (keep alphabetical order):

```yaml
          - { local: 'nexus-observability-serialization', remote: 'observability-serialization' }
```

- [ ] **Step 4: Validate**

Run: `docker compose exec -T php composer validate --no-check-publish`
Expected: `./composer.json is valid`
Run: `grep -c "local: '" .github/workflows/split.yml`
Expected: `38`

- [ ] **Step 5: Commit**

```bash
git add composer.json .github/workflows/split.yml
git commit -m "fix(packaging): rename monorepo composer package, split observability-serialization"
```

### Task 2: Skeleton portability — installable outside the monorepo

**Files:**
- Modify: `packages/nexus-skeleton/composer.json`
- Create: `packages/nexus-skeleton/bin/link-monorepo`
- Create: `packages/nexus-skeleton/.env.example`
- Create: `packages/nexus-skeleton/.gitignore`
- Delete (git rm): `packages/nexus-skeleton/composer.lock`, `packages/nexus-skeleton/.env`, `packages/nexus-skeleton/.phpunit.result.cache`

**Interfaces:**
- Produces: `bin/link-monorepo` (invoked by CI in Task 7); composer scripts `post-create-project-cmd` (runs `.env` copy + `nexus:setup`, wired in Task 4).

- [ ] **Step 1: Rewrite `packages/nexus-skeleton/composer.json`**

Remove the whole `"repositories"` block; add scripts. Full desired content of the changed keys (keep existing `require`, `require-dev`, `autoload`, `autoload-dev`, `minimum-stability`, `prefer-stable`, `config` as-is):

```json
{
    "name": "nexus-actors/skeleton",
    "description": "Minimal skeleton for building applications on the Nexus actor system (PHP 8.5+).",
    "type": "project",
    "license": "MIT",
    "scripts": {
        "post-create-project-cmd": [
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
            "@php bin/console nexus:setup"
        ]
    }
}
```

- [ ] **Step 2: Create `packages/nexus-skeleton/.env.example`**

```dotenv
APP_NAME=my-app
```

- [ ] **Step 3: Create `packages/nexus-skeleton/.gitignore`**

```gitignore
/vendor/
/.env
/composer.lock
/.phpunit.result.cache
```

- [ ] **Step 4: Remove committed artifacts**

```bash
git rm --cached packages/nexus-skeleton/composer.lock packages/nexus-skeleton/.env packages/nexus-skeleton/.phpunit.result.cache
```

- [ ] **Step 5: Create `packages/nexus-skeleton/bin/link-monorepo`**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

// Injects monorepo path repositories into this composer.json so the skeleton
// can be installed/tested against the local packages instead of Packagist.
// Used by monorepo CI only — never ship this to created projects' docs.

$composerFile = dirname(__DIR__) . '/composer.json';
/** @var array<string, mixed> $composer */
$composer = json_decode((string) file_get_contents($composerFile), true, 512, JSON_THROW_ON_ERROR);

$packagesDir = dirname(__DIR__, 2);
$repositories = [];

foreach (glob($packagesDir . '/nexus-*') ?: [] as $dir) {
    if (is_file($dir . '/composer.json')) {
        $repositories[] = ['type' => 'path', 'url' => $dir];
    }
}

$composer['repositories'] = $repositories;

file_put_contents(
    $composerFile,
    json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);

echo 'Linked ' . count($repositories) . " monorepo packages into composer.json\n";
```

Then: `chmod +x packages/nexus-skeleton/bin/link-monorepo`

- [ ] **Step 6: Verify**

Run: `php packages/nexus-skeleton/bin/link-monorepo && git diff --stat packages/nexus-skeleton/composer.json && git checkout packages/nexus-skeleton/composer.json`
Expected: "Linked N monorepo packages…" with N >= 30, diff shows repositories added, then reverted.
Run: `composer validate --no-check-publish -d packages/nexus-skeleton`
Expected: valid (warnings about dev deps acceptable).

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-skeleton .gitignore
git commit -m "feat(skeleton): installable outside the monorepo — no path repos, env template, link script"
```

### Task 3: Recipe catalog

**Files:**
- Create: `packages/nexus-skeleton/src/Setup/Recipe.php`
- Create: `packages/nexus-skeleton/src/Setup/Recipes.php`
- Test: `packages/nexus-skeleton/tests/RecipesTest.php`

**Interfaces:**
- Produces: `Recipe` readonly VO (`key`, `label`, `experimental`, `packages: list<string>`, `configFile: ?string`, `configTemplate: ?string`, `docUrl: string`); `Recipes::all(): array<string, Recipe>`; `Recipes::get(string $key): Recipe`. Consumed by `SetupCommand` (Task 4).

- [ ] **Step 1: Write the failing test `packages/nexus-skeleton/tests/RecipesTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use App\Setup\Recipes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecipesTest extends TestCase
{
    #[Test]
    public function catalog_contains_every_wizard_module(): void
    {
        $keys = array_keys(Recipes::all());
        sort($keys);

        self::assertSame(
            ['cluster', 'http', 'messenger', 'otel', 'persistence-dbal', 'persistence-doctrine', 'persistence-memory', 'swoole'],
            $keys,
        );
    }

    #[Test]
    public function experimental_flags_match_stability_matrix(): void
    {
        self::assertTrue(Recipes::get('cluster')->experimental);
        self::assertTrue(Recipes::get('messenger')->experimental);
        self::assertTrue(Recipes::get('persistence-memory')->experimental);
        self::assertFalse(Recipes::get('swoole')->experimental);
        self::assertFalse(Recipes::get('otel')->experimental);
    }

    #[Test]
    public function every_recipe_declares_packages_and_doc_url(): void
    {
        foreach (Recipes::all() as $recipe) {
            self::assertNotEmpty($recipe->packages);
            self::assertStringStartsWith('https://docs.nexusactors.com/', $recipe->docUrl);
        }
    }

    #[Test]
    public function swoole_recipe_overwrites_runtime_config(): void
    {
        $swoole = Recipes::get('swoole');

        self::assertSame('runtime.php', $swoole->configFile);
        self::assertStringContainsString('SwooleRuntime', (string) $swoole->configTemplate);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd packages/nexus-skeleton && php bin/link-monorepo && composer update --quiet && vendor/bin/phpunit tests/RecipesTest.php`
Expected: FAIL — `Class "App\Setup\Recipes" not found`.

- [ ] **Step 3: Create `packages/nexus-skeleton/src/Setup/Recipe.php`**

```php
<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * @psalm-api consumed by SetupCommand
 */
final readonly class Recipe
{
    /**
     * @param list<string> $packages composer package names to require
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $experimental,
        public array $packages,
        public ?string $configFile,
        public ?string $configTemplate,
        public string $docUrl,
    ) {}
}
```

- [ ] **Step 4: Create `packages/nexus-skeleton/src/Setup/Recipes.php`**

```php
<?php

declare(strict_types=1);

namespace App\Setup;

use InvalidArgumentException;

use function sprintf;

/**
 * Static module catalog for the nexus:setup wizard.
 *
 * @psalm-api consumed by SetupCommand
 */
final class Recipes
{
    private const string SWOOLE_RUNTIME_TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Monadial\Nexus\Runtime\Swoole\SwooleRuntime;

        return static fn(): SwooleRuntime => new SwooleRuntime();

        PHP;

    private const string OPTIONS_TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        // Options for the %s module. Consumed by your bootstrap code:
        //   $options = require __DIR__ . '/config/packages/%s';
        // Docs: %s

        return [
        ];

        PHP;

    /**
     * @return array<string, Recipe>
     */
    public static function all(): array
    {
        return [
            'cluster' => new Recipe(
                key: 'cluster',
                label: 'TCP cluster',
                experimental: true,
                packages: ['nexus-actors/cluster-tcp'],
                configFile: 'cluster.php',
                configTemplate: self::options('TCP cluster', 'cluster.php', 'https://docs.nexusactors.com/docs/guides/clustering-over-tcp'),
                docUrl: 'https://docs.nexusactors.com/docs/guides/clustering-over-tcp',
            ),
            'http' => new Recipe(
                key: 'http',
                label: 'HTTP server (Swoole)',
                experimental: false,
                packages: ['nexus-actors/http', 'nexus-actors/http-server-swoole'],
                configFile: 'http.php',
                configTemplate: self::options('HTTP server', 'http.php', 'https://docs.nexusactors.com/docs/http/overview'),
                docUrl: 'https://docs.nexusactors.com/docs/http/overview',
            ),
            'messenger' => new Recipe(
                key: 'messenger',
                label: 'Symfony Messenger bridge',
                experimental: true,
                packages: ['nexus-actors/messenger'],
                configFile: 'messenger.php',
                configTemplate: self::options('Messenger bridge', 'messenger.php', 'https://docs.nexusactors.com/docs/guides/messenger-bridge'),
                docUrl: 'https://docs.nexusactors.com/docs/guides/messenger-bridge',
            ),
            'otel' => new Recipe(
                key: 'otel',
                label: 'OpenTelemetry observability',
                experimental: false,
                packages: ['nexus-actors/observability-otel'],
                configFile: 'observability.php',
                configTemplate: self::options('OpenTelemetry', 'observability.php', 'https://docs.nexusactors.com/docs/observability/overview'),
                docUrl: 'https://docs.nexusactors.com/docs/observability/overview',
            ),
            'persistence-dbal' => new Recipe(
                key: 'persistence-dbal',
                label: 'Persistence (Doctrine DBAL store)',
                experimental: true,
                packages: ['nexus-actors/persistence', 'nexus-actors/persistence-dbal'],
                configFile: 'persistence.php',
                configTemplate: self::options('persistence', 'persistence.php', 'https://docs.nexusactors.com/docs/persistence/overview'),
                docUrl: 'https://docs.nexusactors.com/docs/persistence/overview',
            ),
            'persistence-doctrine' => new Recipe(
                key: 'persistence-doctrine',
                label: 'Persistence (Doctrine ORM store)',
                experimental: true,
                packages: ['nexus-actors/persistence', 'nexus-actors/persistence-doctrine'],
                configFile: 'persistence.php',
                configTemplate: self::options('persistence', 'persistence.php', 'https://docs.nexusactors.com/docs/persistence/overview'),
                docUrl: 'https://docs.nexusactors.com/docs/persistence/overview',
            ),
            'persistence-memory' => new Recipe(
                key: 'persistence-memory',
                label: 'Persistence (in-memory store)',
                experimental: true,
                packages: ['nexus-actors/persistence'],
                configFile: 'persistence.php',
                configTemplate: self::options('persistence', 'persistence.php', 'https://docs.nexusactors.com/docs/persistence/overview'),
                docUrl: 'https://docs.nexusactors.com/docs/persistence/overview',
            ),
            'swoole' => new Recipe(
                key: 'swoole',
                label: 'Swoole runtime',
                experimental: false,
                packages: ['nexus-actors/runtime-swoole'],
                configFile: 'runtime.php',
                configTemplate: self::SWOOLE_RUNTIME_TEMPLATE,
                docUrl: 'https://docs.nexusactors.com/docs/runtimes/swoole',
            ),
        ];
    }

    public static function get(string $key): Recipe
    {
        $all = self::all();

        if (!isset($all[$key])) {
            throw new InvalidArgumentException(sprintf('Unknown recipe "%s".', $key));
        }

        return $all[$key];
    }

    private static function options(string $module, string $file, string $docUrl): string
    {
        return sprintf(self::OPTIONS_TEMPLATE, $module, $file, $docUrl);
    }
}
```

- [ ] **Step 5: Verify doc URLs exist** (fix any that 404 by picking the nearest real page from `website/build/sitemap.xml`)

Run: `for u in guides/clustering-over-tcp http/overview guides/messenger-bridge observability/overview persistence/overview runtimes/swoole; do echo -n "$u: "; curl -s -o /dev/null -w "%{http_code}\n" "https://docs.nexusactors.com/docs/$u"; done`
Expected: all `200`. If any 404: `grep -o '<loc>[^<]*</loc>' website/build/sitemap.xml | grep <topic>` and substitute the real slug in `Recipes.php` (and in this test's expectation).

- [ ] **Step 6: Run test to verify it passes**

Run: `cd packages/nexus-skeleton && vendor/bin/phpunit tests/RecipesTest.php`
Expected: PASS (4 tests). Restore composer.json afterwards: `git checkout packages/nexus-skeleton/composer.json` (keeps the link-monorepo injection out of the commit).

- [ ] **Step 7: Commit**

```bash
git add packages/nexus-skeleton/src/Setup packages/nexus-skeleton/tests/RecipesTest.php
git commit -m "feat(skeleton): recipe catalog for the setup wizard"
```

### Task 4: `nexus:setup` wizard command

**Files:**
- Create: `packages/nexus-skeleton/src/Command/SetupCommand.php`
- Modify: `packages/nexus-skeleton/bin/console` (register the command)
- Modify: `packages/nexus-skeleton/src/Command/RunCommand.php:20` (add `run` alias)
- Test: `packages/nexus-skeleton/tests/SetupCommandTest.php`

**Interfaces:**
- Consumes: `Recipes::all()` / `Recipe` from Task 3.
- Produces: console command `nexus:setup` with `__construct(?callable $composerRunner = null, ?string $projectDir = null)`; the runner receives `list<string>` package names. `bin/console run` alias for `nexus:run`.

- [ ] **Step 1: Write the failing test `packages/nexus-skeleton/tests/SetupCommandTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests;

use App\Command\SetupCommand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandTest extends TestCase
{
    private string $dir;

    /** @var list<list<string>> */
    private array $required = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nexus-setup-' . uniqid();
        mkdir($this->dir . '/config/packages', 0o777, true);
        file_put_contents($this->dir . '/config/packages/runtime.php', "<?php return static fn() => null;\n");
        $this->required = [];
    }

    private function tester(): CommandTester
    {
        $runner = function (array $packages): int {
            $this->required[] = $packages;

            return 0;
        };

        return new CommandTester(new SetupCommand($runner, $this->dir));
    }

    #[Test]
    public function non_interactive_takes_defaults_and_requires_nothing(): void
    {
        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([], $this->required);
        self::assertStringContainsString('bin/console make:actor', $tester->getDisplay());
        self::assertStringContainsString('FiberRuntime', (string) file_get_contents($this->dir . '/config/packages/runtime.php'));
    }

    #[Test]
    public function swoole_choice_overwrites_runtime_config_and_requires_package(): void
    {
        $tester = $this->tester();
        // runtime=swoole, http=no, persistence=none, observability=none, cluster=no, messenger=no
        $tester->setInputs(['swoole', 'no', 'none', 'none', 'no', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame([['nexus-actors/runtime-swoole']], $this->required);
        self::assertStringContainsString('SwooleRuntime', (string) file_get_contents($this->dir . '/config/packages/runtime.php'));
    }

    #[Test]
    public function experimental_choice_prints_warning_and_writes_config(): void
    {
        $tester = $this->tester();
        // runtime=fiber, persistence=memory, observability=none, cluster=yes, messenger=no
        $tester->setInputs(['fiber', 'memory', 'none', 'yes', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('experimental, not production-ready', $tester->getDisplay());
        self::assertFileExists($this->dir . '/config/packages/persistence.php');
        self::assertFileExists($this->dir . '/config/packages/cluster.php');
        self::assertSame([['nexus-actors/persistence', 'nexus-actors/cluster-tcp']], $this->required);
    }

    #[Test]
    public function http_question_is_skipped_on_fiber(): void
    {
        $tester = $this->tester();
        // runtime=fiber → no http question: persistence=none, observability=none, cluster=no, messenger=no
        $tester->setInputs(['fiber', 'none', 'none', 'no', 'no']);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringNotContainsString('HTTP server', $tester->getDisplay());
    }

    #[Test]
    public function existing_module_config_is_not_overwritten(): void
    {
        file_put_contents($this->dir . '/config/packages/cluster.php', "<?php return ['keep' => true];\n");

        $tester = $this->tester();
        $tester->setInputs(['fiber', 'none', 'none', 'yes', 'no']);
        $tester->execute([]);

        self::assertStringContainsString('keep', (string) file_get_contents($this->dir . '/config/packages/cluster.php'));
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd packages/nexus-skeleton && php bin/link-monorepo && composer update --quiet && vendor/bin/phpunit tests/SetupCommandTest.php`
Expected: FAIL — `Class "App\Command\SetupCommand" not found`.

- [ ] **Step 3: Create `packages/nexus-skeleton/src/Command/SetupCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Command;

use App\Setup\Recipe;
use App\Setup\Recipes;
use Closure;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_merge;
use function array_values;
use function dirname;
use function extension_loaded;
use function file_exists;
use function file_put_contents;
use function implode;
use function sprintf;

/**
 * Interactive project setup. Runs on create-project and stays re-runnable.
 *
 * @psalm-api registered in bin/console
 */
#[AsCommand('nexus:setup', 'Configure runtime and optional modules for this project')]
final class SetupCommand extends Command
{
    /** @var Closure(list<string>): int */
    private readonly Closure $composerRunner;

    private readonly string $projectDir;

    /**
     * @param callable(list<string>): int|null $composerRunner
     */
    public function __construct(?callable $composerRunner = null, ?string $projectDir = null)
    {
        parent::__construct();

        $this->composerRunner = $composerRunner !== null
            ? $composerRunner(...)
            : static function (array $packages): int {
                $exit = 1;
                passthru('composer require --with-all-dependencies ' . implode(' ', $packages), $exit);

                return $exit;
            };
        $this->projectDir = $projectDir ?? dirname(__DIR__, 2);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Nexus project setup');

        $chosen = $input->isInteractive()
            ? $this->ask($io)
            : [];

        $packages = [];

        foreach ($chosen as $recipe) {
            if ($recipe->experimental) {
                $io->warning(sprintf('%s is experimental, not production-ready; APIs may change.', $recipe->label));
            }

            $this->writeConfig($io, $recipe);
            $packages[] = $recipe->packages;
        }

        $packages = array_merge(...array_values($packages ?: [[]]));

        if ($packages !== []) {
            $io->section('Installing packages');

            if (($this->composerRunner)($packages) !== 0) {
                $io->error('composer require failed — run it manually: composer require ' . implode(' ', $packages));

                return Command::FAILURE;
            }
        }

        $io->success('Project configured.');
        $io->listing([
            'Create your first actor:  bin/console make:actor Greeter',
            'Run the actor system:     bin/console run',
            'Re-run this wizard later: bin/console nexus:setup',
        ]);

        foreach ($chosen as $recipe) {
            $io->text(sprintf('%s docs: %s', $recipe->label, $recipe->docUrl));
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<Recipe>
     */
    private function ask(SymfonyStyle $io): array
    {
        $chosen = [];

        $runtime = $io->choice('Runtime', ['fiber', 'swoole'], 'fiber');

        if ($runtime === 'swoole') {
            if (!extension_loaded('swoole')) {
                $io->warning('ext-swoole >= 6.2.1 is required for the Swoole runtime but is not loaded in this PHP.');
            }

            $chosen[] = Recipes::get('swoole');

            if ($io->confirm('Add the HTTP server (Swoole)?', false)) {
                $chosen[] = Recipes::get('http');
            }
        }

        $persistence = $io->choice(
            'Persistence store (experimental)',
            ['none', 'memory', 'dbal', 'doctrine'],
            'none',
        );

        if ($persistence !== 'none') {
            $chosen[] = Recipes::get('persistence-' . $persistence);
        }

        if ($io->choice('Observability', ['none', 'otel'], 'none') === 'otel') {
            $chosen[] = Recipes::get('otel');
        }

        if ($io->confirm('Add TCP clustering (experimental)?', false)) {
            $chosen[] = Recipes::get('cluster');
        }

        if ($io->confirm('Add the Symfony Messenger bridge (experimental)?', false)) {
            $chosen[] = Recipes::get('messenger');
        }

        return $chosen;
    }

    private function writeConfig(SymfonyStyle $io, Recipe $recipe): void
    {
        if ($recipe->configFile === null || $recipe->configTemplate === null) {
            return;
        }

        $path = $this->projectDir . '/config/packages/' . $recipe->configFile;

        // runtime.php ships with the skeleton; the Swoole recipe intentionally replaces it.
        if ($recipe->configFile !== 'runtime.php' && file_exists($path)) {
            $io->text(sprintf('config/packages/%s already exists — left untouched.', $recipe->configFile));

            return;
        }

        file_put_contents($path, $recipe->configTemplate);
        $io->text(sprintf('Wrote config/packages/%s', $recipe->configFile));
    }
}
```

- [ ] **Step 4: Register in `bin/console` and alias RunCommand**

In `packages/nexus-skeleton/bin/console` add after the `RunCommand` registration:

```php
$app->add(new SetupCommand());
```

(and add `use App\Command\SetupCommand;` to the imports). In `RunCommand.php` change the attribute:

```php
#[AsCommand('nexus:run', 'Boot the actor system and run until shutdown', aliases: ['run'])]
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd packages/nexus-skeleton && vendor/bin/phpunit`
Expected: PASS — all suites including the 5 new SetupCommand tests. Then `git checkout packages/nexus-skeleton/composer.json`.

- [ ] **Step 6: Commit**

```bash
git add packages/nexus-skeleton/src/Command packages/nexus-skeleton/bin/console packages/nexus-skeleton/tests/SetupCommandTest.php
git commit -m "feat(skeleton): interactive nexus:setup wizard with module recipes"
```

### Task 5: `nexus-actors/maker` package

**Files:**
- Create: `packages/nexus-maker/composer.json`
- Create: `packages/nexus-maker/src/MakerCommands.php`
- Create: `packages/nexus-maker/src/MakeActorCommand.php`
- Create: `packages/nexus-maker/src/MakeMessageCommand.php`
- Test: `packages/nexus-maker/tests/MakeCommandsTest.php`
- Modify: `packages/nexus-skeleton/composer.json` (add require-dev `nexus-actors/maker`)

**Interfaces:**
- Produces: `Nexus\Maker\MakerCommands::all(string $projectDir): list<Symfony\Component\Console\Command\Command>` — the exact hook `packages/nexus-skeleton/bin/console` already calls; commands `make:actor <name> [--with-message]` and `make:message <name>`.

- [ ] **Step 1: Create `packages/nexus-maker/composer.json`**

```json
{
    "name": "nexus-actors/maker",
    "description": "Code generators (make:actor, make:message) for Nexus skeleton projects.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "symfony/console": "^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0"
    },
    "autoload": {
        "psr-4": { "Nexus\\Maker\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Nexus\\Maker\\Tests\\": "tests/" }
    },
    "config": {
        "sort-packages": true
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

- [ ] **Step 2: Write the failing test `packages/nexus-maker/tests/MakeCommandsTest.php`**

```php
<?php

declare(strict_types=1);

namespace Nexus\Maker\Tests;

use Nexus\Maker\MakerCommands;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class MakeCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nexus-maker-' . uniqid();
        mkdir($this->dir . '/src', 0o777, true);
    }

    private function run(string $name, array $input): CommandTester
    {
        foreach (MakerCommands::all($this->dir) as $command) {
            if ($command->getName() === $name) {
                $tester = new CommandTester($command);
                $tester->execute($input);

                return $tester;
            }
        }

        self::fail(sprintf('Command %s not registered', $name));
    }

    #[Test]
    public function all_registers_both_commands(): void
    {
        $names = array_map(
            static fn($c): ?string => $c->getName(),
            MakerCommands::all($this->dir),
        );
        sort($names);

        self::assertSame(['make:actor', 'make:message'], $names);
    }

    #[Test]
    public function make_actor_generates_valid_handler(): void
    {
        $tester = $this->run('make:actor', ['name' => 'Payment']);

        $tester->assertCommandIsSuccessful();
        $file = $this->dir . '/src/Actor/PaymentActor.php';
        self::assertFileExists($file);

        $code = (string) file_get_contents($file);
        self::assertStringContainsString("#[AsActor('payment')]", $code);
        self::assertStringContainsString('final readonly class PaymentActor implements ActorHandler', $code);

        exec('php -l ' . escapeshellarg($file), $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }

    #[Test]
    public function make_actor_with_message_generates_both(): void
    {
        $this->run('make:actor', ['name' => 'Payment', '--with-message' => true]);

        self::assertFileExists($this->dir . '/src/Actor/PaymentActor.php');
        self::assertFileExists($this->dir . '/src/Message/PaymentMessage.php');
    }

    #[Test]
    public function make_message_generates_readonly_class(): void
    {
        $tester = $this->run('make:message', ['name' => 'OrderPlaced']);

        $tester->assertCommandIsSuccessful();
        $code = (string) file_get_contents($this->dir . '/src/Message/OrderPlaced.php');
        self::assertStringContainsString('final readonly class OrderPlaced', $code);

        exec('php -l ' . escapeshellarg($this->dir . '/src/Message/OrderPlaced.php'), $out, $exit);
        self::assertSame(0, $exit);
    }

    #[Test]
    public function generators_refuse_to_overwrite(): void
    {
        $this->run('make:message', ['name' => 'OrderPlaced']);
        $tester = $this->run('make:message', ['name' => 'OrderPlaced']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }
}
```

- [ ] **Step 3: Run to verify it fails**

Run: `cd packages/nexus-maker && composer update --quiet && vendor/bin/phpunit`
Expected: FAIL — `Class "Nexus\Maker\MakerCommands" not found`.

- [ ] **Step 4: Create `packages/nexus-maker/src/MakerCommands.php`**

```php
<?php

declare(strict_types=1);

namespace Nexus\Maker;

use Symfony\Component\Console\Command\Command;

/**
 * @psalm-api entry point consumed by skeleton bin/console
 */
final class MakerCommands
{
    /**
     * @return list<Command>
     */
    public static function all(string $projectDir): array
    {
        return [
            new MakeActorCommand($projectDir),
            new MakeMessageCommand($projectDir),
        ];
    }
}
```

- [ ] **Step 5: Create `packages/nexus-maker/src/MakeActorCommand.php`**

```php
<?php

declare(strict_types=1);

namespace Nexus\Maker;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function dirname;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function lcfirst;
use function mkdir;
use function preg_replace;
use function sprintf;
use function strtolower;
use function ucfirst;

/**
 * @psalm-api registered via MakerCommands::all()
 */
#[AsCommand('make:actor', 'Generate an #[AsActor] handler in src/Actor')]
final class MakeActorCommand extends Command
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Actor;

        use Monadial\Nexus\App\AsActor;
        use Monadial\Nexus\Core\Actor\ActorContext;
        use Monadial\Nexus\Core\Actor\ActorHandler;
        use Monadial\Nexus\Core\Actor\Behavior;
        use Override;

        /**
         * @implements ActorHandler<object>
         */
        #[AsActor('%s')]
        final readonly class %sActor implements ActorHandler
        {
            #[Override]
            public function handle(ActorContext $ctx, object $message): Behavior
            {
                return match (true) {
                    // $message instanceof %sMessage => $this->on%s($ctx, $message),
                    default => Behavior::unhandled(),
                };
            }
        }

        PHP;

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Actor name, e.g. Payment');
        $this->addOption('with-message', null, InputOption::VALUE_NONE, 'Also generate src/Message/<Name>Message.php');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $raw */
        $raw = $input->getArgument('name');
        $name = ucfirst((string) preg_replace('/Actor$/', '', $raw));
        $file = $this->projectDir . '/src/Actor/' . $name . 'Actor.php';

        if (file_exists($file)) {
            $io->error(sprintf('%s already exists.', $file));

            return Command::FAILURE;
        }

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }

        file_put_contents($file, sprintf(self::TEMPLATE, strtolower(lcfirst($name)), $name, $name, $name));
        $io->success(sprintf('Created src/Actor/%sActor.php', $name));

        if ($input->getOption('with-message') === true) {
            $message = new MakeMessageCommand($this->projectDir);
            $message->setApplication($this->getApplication());

            return $message->run(
                new \Symfony\Component\Console\Input\ArrayInput(['name' => $name . 'Message']),
                $output,
            );
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 6: Create `packages/nexus-maker/src/MakeMessageCommand.php`**

```php
<?php

declare(strict_types=1);

namespace Nexus\Maker;

use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function dirname;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function sprintf;
use function ucfirst;

/**
 * @psalm-api registered via MakerCommands::all()
 */
#[AsCommand('make:message', 'Generate a readonly message class in src/Message')]
final class MakeMessageCommand extends Command
{
    private const string TEMPLATE = <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Message;

        final readonly class %s
        {
            public function __construct()
            {
            }
        }

        PHP;

    public function __construct(private readonly string $projectDir)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Message name, e.g. OrderPlaced');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $raw */
        $raw = $input->getArgument('name');
        $name = ucfirst($raw);
        $file = $this->projectDir . '/src/Message/' . $name . '.php';

        if (file_exists($file)) {
            $io->error(sprintf('%s already exists.', $file));

            return Command::FAILURE;
        }

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }

        file_put_contents($file, sprintf(self::TEMPLATE, $name));
        $io->success(sprintf('Created src/Message/%s.php', $name));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd packages/nexus-maker && vendor/bin/phpunit`
Expected: PASS (5 tests).

- [ ] **Step 8: Add maker to the skeleton's require-dev**

In `packages/nexus-skeleton/composer.json`:

```json
    "require-dev": {
        "nexus-actors/maker": "dev-main",
        "phpunit/phpunit": "^13.0"
    }
```

- [ ] **Step 9: Verify the bin/console hook picks it up**

Run: `cd packages/nexus-skeleton && php bin/link-monorepo && composer update --quiet && php bin/console list | grep make:`
Expected: `make:actor` and `make:message` listed. Then `git checkout composer.json` — wait, the require-dev change must SURVIVE; only the repositories injection must not. Run instead: `php -r '$c=json_decode(file_get_contents("composer.json"),true); unset($c["repositories"]); file_put_contents("composer.json", json_encode($c, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");'`

- [ ] **Step 10: Commit**

```bash
git add packages/nexus-maker packages/nexus-skeleton/composer.json
git commit -m "feat(maker): make:actor and make:message generators wired into the skeleton"
```

### Task 6: Skeleton README

**Files:**
- Create: `packages/nexus-skeleton/README.md`

**Interfaces:**
- Consumes: command names from Tasks 4-5 (`nexus:setup`, `run`, `make:actor`, `make:message`).

- [ ] **Step 1: Write `packages/nexus-skeleton/README.md`**

```markdown
# Nexus Skeleton

Start a [Nexus](https://nexusactors.com) actor-system project in three commands:

```bash
composer create-project nexus-actors/skeleton my-app --stability=dev
cd my-app
bin/console run
```

`create-project` launches an interactive setup wizard (`nexus:setup`) that picks your
runtime (Fiber for development, Swoole for production) and optional modules —
persistence, OpenTelemetry observability, TCP clustering, and the Symfony Messenger
bridge. Modules marked *experimental* are pre-1.0: not production-ready, APIs may change.

## Everyday commands

```bash
bin/console make:actor Payment --with-message   # generate src/Actor/PaymentActor.php + message
bin/console make:message OrderPlaced            # generate src/Message/OrderPlaced.php
bin/console run                                 # boot the actor system (alias of nexus:run)
bin/console nexus:setup                         # re-run the wizard to add modules later
```

## Project layout

```
bin/console            command-line entry point
config/services.php    DI container config (autowires src/)
config/packages/       per-module config (runtime.php picks your Runtime)
src/Actor/             #[AsActor] handlers — auto-spawned at boot
src/Message/           message classes
src/Kernel.php         boots the container and the ActorSystem
```

Actors are plain classes with `#[AsActor('name')]` — the Kernel spawns every tagged
handler at boot. See the [Quick Start](https://docs.nexusactors.com/docs/getting-started/quick-start)
for a guided tour.
```

- [ ] **Step 2: Verify the quick-start URL**

Run: `curl -s -o /dev/null -w "%{http_code}\n" https://docs.nexusactors.com/docs/getting-started/quick-start`
Expected: `200` (if 404, find the real slug: `grep -o '<loc>[^<]*quick[^<]*</loc>' website/build/sitemap.xml` and substitute).

- [ ] **Step 3: Commit**

```bash
git add packages/nexus-skeleton/README.md
git commit -m "docs(skeleton): three-command quickstart README"
```

### Task 7: CI create-project smoke job

**Files:**
- Create: `.github/workflows/skeleton-smoke.yml`

**Interfaces:**
- Consumes: `bin/link-monorepo` (Task 2), `nexus:setup --no-interaction` (Task 4), `run` alias (Task 4), maker commands (Task 5).

- [ ] **Step 1: Create `.github/workflows/skeleton-smoke.yml`**

```yaml
# Validates the real user flow: create-project from the skeleton, wizard defaults,
# generate an actor, boot the system. Uses path repositories against the monorepo
# (Packagist registration is a separate, manual step).
name: skeleton — create-project smoke

on:
  push:
    branches: [main]
    paths:
      - 'packages/nexus-skeleton/**'
      - 'packages/nexus-maker/**'
      - '.github/workflows/skeleton-smoke.yml'
  pull_request:
    paths:
      - 'packages/nexus-skeleton/**'
      - 'packages/nexus-maker/**'
      - '.github/workflows/skeleton-smoke.yml'
  workflow_dispatch:

jobs:
  smoke:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          tools: composer

      - name: Package unit tests (skeleton + maker)
        run: |
          php packages/nexus-skeleton/bin/link-monorepo
          composer update -d packages/nexus-skeleton --quiet
          packages/nexus-skeleton/vendor/bin/phpunit -c packages/nexus-skeleton
          composer update -d packages/nexus-maker --quiet
          packages/nexus-maker/vendor/bin/phpunit -c packages/nexus-maker

      - name: create-project from local path
        run: |
          composer create-project nexus-actors/skeleton /tmp/app \
            --repository="{\"type\":\"path\",\"url\":\"$PWD/packages/nexus-skeleton\"}" \
            --stability=dev --no-interaction

      - name: Generate an actor and boot
        working-directory: /tmp/app
        run: |
          php bin/console make:actor Smoke --with-message
          php -l src/Actor/SmokeActor.php
          timeout 10 php bin/console run > run.log 2>&1 || test $? -eq 124
          grep "Booting Nexus actor system" run.log
```

- [ ] **Step 2: Validate the workflow syntax**

Run: `gh workflow view "skeleton — create-project smoke" 2>/dev/null || python3 -c "import yaml; yaml.safe_load(open('.github/workflows/skeleton-smoke.yml')); print('yaml ok')"`
Expected: `yaml ok` (workflow not visible until pushed).

Note: `create-project` from a path repository copies the package directory — including the injected `repositories` from `link-monorepo` in the previous step, so the created project resolves `dev-main` deps from the monorepo. This is why link-monorepo runs first.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/skeleton-smoke.yml
git commit -m "ci(skeleton): create-project smoke job — wizard defaults, make:actor, boot"
```

### Task 8: Landing `/quickstart` page, Bootstrap removal

**Files:**
- Create: `landing/src/pages/quickstart.astro`
- Delete: `landing/src/pages/bootstrap.astro`, `landing/src/components/BootstrapWizard.tsx`, `landing/src/lib/bootstrapConfig.ts`
- Modify: `landing/src/components/Nav.astro:34-39`, `landing/src/components/MobileNav.tsx:35-38`, `landing/src/layouts/Base.astro:83`

**Interfaces:**
- Consumes: command surface from Tasks 4-6 (`create-project … --stability=dev`, wizard question order, `make:actor`, `bin/console run`).

- [ ] **Step 1: Create `landing/src/pages/quickstart.astro`**

```astro
---
import Marketing from '../layouts/Marketing.astro';
import CodeBlock from '../components/CodeBlock.astro';
import CtaBlock from '../components/CtaBlock.astro';
import { docsUrl } from '../lib/urls';

const createCode = `composer create-project nexus-actors/skeleton my-app --stability=dev
cd my-app`;

const wizardCode = `  Nexus project setup
  ===================

 Runtime [fiber]:
  [0] fiber
  [1] swoole
 > swoole

 Add the HTTP server (Swoole)? (yes/no) [no]:
 > yes

 Persistence store (experimental) [none]:
  [0] none  [1] memory  [2] dbal  [3] doctrine
 > none

 Observability [none]:
  [0] none  [1] otel
 > otel

 Add TCP clustering (experimental)? (yes/no) [no]:
 > no

 Add the Symfony Messenger bridge (experimental)? (yes/no) [no]:
 > no

 [OK] Project configured.

 * Create your first actor:  bin/console make:actor Greeter
 * Run the actor system:     bin/console run`;

const makeCode = `$ bin/console make:actor Payment --with-message

 [OK] Created src/Actor/PaymentActor.php
 [OK] Created src/Message/PaymentMessage.php`;

const runCode = `$ bin/console run

Booting Nexus actor system…
[payment] spawned via #[AsActor('payment')]`;

const treeCode = `my-app/
├── bin/console            command-line entry point
├── config/
│   ├── services.php       DI container (autowires src/)
│   └── packages/          per-module config — runtime.php picks your Runtime
└── src/
    ├── Actor/             #[AsActor] handlers, auto-spawned at boot
    ├── Message/           readonly message classes
    └── Kernel.php         boots the container and ActorSystem`;
---
<Marketing
  title="Quickstart — Nexus"
  description="Start a Nexus actor project in three commands: create-project, answer the setup wizard, run. Generators included."
>
  <!-- Hero -->
  <section class="max-w-6xl mx-auto px-4 py-16 md:py-24 grid md:grid-cols-2 gap-12 items-center">
    <div>
      <p class="text-primary font-semibold text-sm uppercase tracking-wide mb-3">Quickstart</p>
      <h1 class="text-3xl sm:text-5xl font-extrabold mb-6 leading-tight">
        Zero to actors in three commands.
      </h1>
      <p class="text-xl text-slate-600 dark:text-slate-300 mb-8">
        <code class="font-mono">create-project</code> scaffolds a Symfony-style project,
        an interactive wizard wires your runtime and modules, and
        <code class="font-mono">make:actor</code> writes the boilerplate. No config
        spelunking on day one.
      </p>
      <div class="flex flex-wrap gap-3">
        <a
          href={docsUrl('/getting-started/quick-start')}
          class="bg-primary hover:bg-primary-dark text-white px-6 py-3 rounded-md font-semibold transition"
        >
          Full guide in the docs
        </a>
      </div>
    </div>
    <CodeBlock code={createCode} lang="bash" title="terminal" />
  </section>

  <!-- Wizard -->
  <section class="max-w-6xl mx-auto px-4 py-16 grid md:grid-cols-2 gap-12 items-center bg-slate-50 dark:bg-slate-800/40 rounded-2xl">
    <div>
      <h2 class="text-3xl font-bold mb-4">Answer six questions.</h2>
      <p class="text-slate-600 dark:text-slate-300 mb-6">
        The wizard runs automatically after <code class="font-mono">create-project</code>
        (and any time later via <code class="font-mono">bin/console nexus:setup</code>).
        It installs only what you pick and labels experimental modules honestly.
      </p>
      <ul class="space-y-2 text-sm text-slate-600 dark:text-slate-400">
        <li class="flex gap-2"><span class="text-primary font-bold">→</span><span>Fiber runtime for development, Swoole for production — swap later by re-running the wizard</span></li>
        <li class="flex gap-2"><span class="text-primary font-bold">→</span><span>Persistence, OpenTelemetry, TCP cluster, and Messenger are opt-in modules</span></li>
        <li class="flex gap-2"><span class="text-primary font-bold">→</span><span>Experimental modules print an explicit warning — no surprises in production</span></li>
      </ul>
    </div>
    <CodeBlock code={wizardCode} lang="text" title="bin/console nexus:setup" />
  </section>

  <!-- Generators -->
  <section class="max-w-6xl mx-auto px-4 py-16 grid md:grid-cols-2 gap-12 items-center">
    <CodeBlock code={makeCode} lang="text" title="terminal" />
    <div>
      <h2 class="text-3xl font-bold mb-4">Generate, don't copy-paste.</h2>
      <p class="text-slate-600 dark:text-slate-300 mb-6">
        <code class="font-mono">make:actor</code> writes an
        <code class="font-mono">#[AsActor]</code> handler the Kernel auto-spawns at boot;
        <code class="font-mono">make:message</code> writes the readonly message class.
        Generated code matches the style of everything else in the project.
      </p>
    </div>
  </section>

  <!-- Run + layout -->
  <section class="max-w-6xl mx-auto px-4 py-16 grid md:grid-cols-2 gap-12 items-center bg-slate-50 dark:bg-slate-800/40 rounded-2xl">
    <div>
      <h2 class="text-3xl font-bold mb-4">Run it.</h2>
      <p class="text-slate-600 dark:text-slate-300 mb-6">
        <code class="font-mono">bin/console run</code> boots the container, spawns every
        <code class="font-mono">#[AsActor]</code> handler, and blocks until shutdown.
        What you get is a small, comprehensible tree:
      </p>
      <CodeBlock code={treeCode} lang="text" title="project layout" />
    </div>
    <CodeBlock code={runCode} lang="text" title="terminal" />
  </section>

  <CtaBlock />
</Marketing>
```

- [ ] **Step 2: Delete the wizard**

```bash
git rm landing/src/pages/bootstrap.astro landing/src/components/BootstrapWizard.tsx landing/src/lib/bootstrapConfig.ts
```

- [ ] **Step 3: Retarget navigation**

`landing/src/components/Nav.astro` (lines 34-39): in the existing CTA pill anchor change ONLY three things — the comment to `<!-- Quickstart CTA pill -->`, `href="/bootstrap"` to `href="/quickstart"`, and the label text `Bootstrap` to `Quickstart`. Do not touch the `class` attribute or anything else in the block.

`landing/src/components/MobileNav.tsx` (lines ~35-38): `href="/bootstrap"` → `href="/quickstart"`, label `Bootstrap` → `Quickstart`.
`landing/src/layouts/Base.astro:83`: `<li><a href="/quickstart">Quickstart</a></li>` (replace the "Bootstrap your app" line).

- [ ] **Step 4: Verify no references remain and the site builds**

Run: `grep -rin "bootstrap" landing/src | grep -v "bootstrap.php" || echo "clean"`
Expected: `clean` (code snippets showing `bootstrap.php` filenames are fine and excluded).
Run: `npm --prefix landing run build`
Expected: build completes; page count unchanged (bootstrap page replaced by quickstart).

- [ ] **Step 5: Commit**

```bash
git add -A landing/src
git commit -m "feat(landing): quickstart page replaces the web bootstrap wizard"
```

### Task 9: Homepage cluster showcase

**Files:**
- Create: `landing/src/components/ClusterShowcase.astro`
- Modify: `landing/src/pages/index.astro` (import + placement after `<ObservabilityShowcase />`)

**Interfaces:**
- Consumes: `CodeBlock.astro`, `docsUrl` — same pattern as `cluster.astro`.

- [ ] **Step 1: Create `landing/src/components/ClusterShowcase.astro`**

```astro
---
import CodeBlock from './CodeBlock.astro';

const code = `<?php
use Monadial\\Nexus\\Cluster\\NodeAddress;
use Monadial\\Nexus\\Cluster\\Tcp\\ClusterNode;
use Monadial\\Nexus\\Cluster\\Tcp\\ClusterTopology;
use Monadial\\Nexus\\Cluster\\Tcp\\NodeEndpoint;

$topology = ClusterTopology::create(
    clusterName:       'production',
    self:              new NodeAddress('production', 'eu', 'orders', 'node-1'),
    bindEndpoint:      NodeEndpoint::fromString('0.0.0.0:7361'),
    advertiseEndpoint: NodeEndpoint::fromString('10.0.0.1:7361'),
    seeds:             [NodeEndpoint::fromString('10.0.0.2:7361')],
);

$node = ClusterNode::boot($system, $topology, $registry);
$node->expose($processorRef);

// Location-transparent: same tell() whether the target is local or remote.
$ref = $node->refFor(OrderProcessor::class, 'orders/processor');
$ref->tell(new OrderPlaced($orderId));`;
---
<section class="max-w-6xl mx-auto px-4 py-16 grid md:grid-cols-2 gap-12 items-center">
  <div>
    <h2 class="text-3xl font-bold mb-4">
      Actors across machines.
      <span class="ml-2 align-middle inline-block rounded-full border border-amber-400/60 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 px-2.5 py-0.5 text-xs font-semibold">Experimental</span>
    </h2>
    <p class="text-slate-600 dark:text-slate-300 mb-6">
      A Swoole TCP mesh joins Nexus nodes into one system: gossip membership,
      phi-accrual failure detection, and the same <code class="font-mono">tell()</code>
      and <code class="font-mono">ask()</code> you already use — whether the target actor
      lives in-process or on another machine.
    </p>
    <a
      href="/cluster"
      class="text-primary font-semibold hover:underline"
    >
      Explore clustering →
    </a>
  </div>
  <CodeBlock code={code} lang="php" title="bootstrap.php — join the mesh" />
</section>
```

- [ ] **Step 2: Wire into `landing/src/pages/index.astro`**

Add the import below `ObservabilityShowcase`:

```astro
import ClusterShowcase from '../components/ClusterShowcase.astro';
```

Add the component after `<ObservabilityShowcase />`:

```astro
  <ObservabilityShowcase />
  <ClusterShowcase />
```

- [ ] **Step 3: Verify `refFor` API against the real package** — the snippet must show real API. Check: `grep -rn "public function refFor\|public function expose" packages/nexus-cluster-tcp/src/ | head -3`. If `refFor`'s signature differs, copy the exact call shape from `landing/src/pages/cluster.astro`'s `refCode` block instead.

- [ ] **Step 4: Build and eyeball**

Run: `npm --prefix landing run build && grep -c "Actors across machines" landing/dist/index.html`
Expected: build ok, count >= 1.

- [ ] **Step 5: Commit**

```bash
git add landing/src/components/ClusterShowcase.astro landing/src/pages/index.astro
git commit -m "feat(landing): cluster showcase on the homepage"
```

### Task 10: Split entries, doc counts, final verification

**Files:**
- Modify: `.github/workflows/split.yml` (add skeleton + maker)
- Modify: `website/docs/contributing/release-process.md` (counts)

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: Add split entries** (alphabetical position)

```yaml
          - { local: 'nexus-maker', remote: 'maker' }
```
```yaml
          - { local: 'nexus-skeleton', remote: 'skeleton' }
```

Run: `grep -c "local: '" .github/workflows/split.yml`
Expected: `40`

- [ ] **Step 2: Update release-process counts**

In `website/docs/contributing/release-process.md`, update the split-entry count (38 after Task 1 → 40) wherever stated, and add `maker`/`skeleton` to any package enumeration. Run `grep -n "38\|37" website/docs/contributing/release-process.md` to find the spots.

- [ ] **Step 3: Full verification suite**

```bash
docker compose exec -T php vendor/bin/psalm --no-progress
docker compose exec -T php vendor/bin/phpunit
npm --prefix landing run build
npm --prefix website run build
grep -rin "bootstrap" landing/src | grep -v "bootstrap.php" || echo landing-clean
```

Expected: all pass; `landing-clean`.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/split.yml website/docs/contributing/release-process.md
git commit -m "chore(packaging): split skeleton and maker, update release-process counts"
```

- [ ] **Step 5: Manual handoff note (do NOT automate):** the project owner must register on Packagist: the 25 missing split packages, plus `nexus-actors/skeleton` and `nexus-actors/maker` once their split repos exist, and re-point `nexus-actors/nexus` to a new meta split repo. Print this list at the end of execution.
