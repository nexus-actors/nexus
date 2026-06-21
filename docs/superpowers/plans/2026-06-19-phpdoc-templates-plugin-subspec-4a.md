# phpDocumentor Templates Plugin — Sub-spec 4a Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `nexus-actors/phpdoc-templates-plugin` — a phpDocumentor v3 plugin (plus Twig template overrides) that captures Psalm/PHPStan `@template`, `@template-extends`, `@template-implements`, `@template-covariant` tags from PHPDoc and renders them as first-class template parameters in the auto-generated API reference. The plugin also emits a flat `api-classes.json` catalog (FQCN → API URL) that sub-spec 6's class-name remark plugin consumes.

**Architecture:** Standalone composer package developed in a separate git repo (not part of the Nexus monorepo). Hooks into phpDocumentor v3's descriptor pipeline via a Symfony event subscriber (the standard v3 extension mechanism), augments class/method descriptors with template-parameter data, and ships Twig template overrides that render that data in the generated HTML. All development + tests run in Docker (per project convention) — the plugin's own Dockerfile pins PHP 8.5.7 + phpDocumentor 3.x.

**Tech Stack:** PHP 8.5.7, phpDocumentor v3 (latest 3.x), phpdocumentor/type-resolver (already parses `@template` syntax), phpdocumentor/reflection, Symfony EventDispatcher (used by phpDocumentor internally), Twig 3, PHPUnit 13, Psalm level 1.

## Global Constraints

Every task in this plan must honor these (from spec §2, §8.1, and CLAUDE.md):

- **Docker for everything.** No host PHP, no host composer, no host phpDocumentor. All commands run via `docker compose exec phpdoc …` inside the plugin's own container.
- **The plugin is a NEW git repo.** Not a branch of the Nexus monorepo. Eventually pushed to `github.com/nexus-actors/phpdoc-templates-plugin`.
- **No `Co-Authored-By: Claude` trailer on commits.** Project rule.
- **New commits only, no `--amend`.** If a check fails, fix forward.
- **Pre-approved hook+GPG bypass** (same environmental constraints as plan 0): `--no-verify --no-gpg-sign` on every commit. Document in commit body.
- **Conventional commit prefixes:** `feat(plugin):`, `fix(plugin):`, `test(plugin):`, `chore(plugin):`, `docs(plugin):`, `chore(ci):`. Use the prefix matching the work.
- **No new features beyond what's specced.** This plan implements exactly the §8.1 deliverables (plugin + Twig overrides + `api-classes.json` emitter + 5-class smoke test). No bonus features.
- **Smoke-test acceptance is the gate:** the 5 named classes (`ActorRef`, `Behavior`, `Props`, `BehaviorWithState`, `Future`) must render literal `<T>` template parameters in:
    - The class header (e.g. `ActorRef<T>` on the page title)
    - Method signatures (e.g. `ask<R>(...)` where R is bound by the method)
    - Type-tree cross-references (where one class references another generic)
- **Runs in Docker against the bumped baseline** (PHP 8.5.7 — same as plan 0's bump). Plugin's Dockerfile pins `php:8.5.7-cli`.

---

## File Structure

The plugin is a standalone composer package. Tree at completion:

```
phpdoc-templates-plugin/                # NEW git repo (not a Nexus branch)
├── .gitignore
├── .dockerignore
├── LICENSE                             # MIT (matches Nexus packages)
├── README.md                           # usage + installation
├── composer.json                       # PHP 8.5.7+, phpDocumentor ^3.5, PHPUnit ^13
├── docker-compose.yml                  # phpdoc + test services
├── Dockerfile                          # php:8.5.7-cli + phpDocumentor PHAR
├── phpunit.xml                         # tests/Unit + tests/Integration
├── phpdoc.dist.xml                     # phpDocumentor config (used for smoke test)
├── psalm.xml                           # level 1
├── phpcs.xml                           # PER-CS2.0 (matches Nexus)
├── Makefile                            # build, up, test, smoke, lint
├── src/
│   ├── Plugin.php                      # plugin entry; registers Symfony services
│   ├── EventSubscriber/
│   │   └── TemplateTagSubscriber.php   # listens to descriptor-build events
│   ├── Descriptor/
│   │   ├── TemplateDescriptor.php      # value object: name + bound + variance
│   │   └── TemplateAwareTrait.php      # mixin for class/method descriptors
│   ├── Reflection/
│   │   └── TemplateTagReader.php       # parses @template tags via type-resolver
│   ├── Template/                       # Twig overrides
│   │   ├── class.html.twig             # extends default, adds <T> rendering
│   │   ├── method.html.twig            # extends default, adds <T> rendering
│   │   └── partials/
│   │       └── template-params.html.twig
│   └── Emitter/
│       └── ApiClassesJsonEmitter.php   # writes api-classes.json post-build
├── tests/
│   ├── Unit/
│   │   ├── Reflection/
│   │   │   └── TemplateTagReaderTest.php
│   │   ├── Descriptor/
│   │   │   └── TemplateDescriptorTest.php
│   │   └── Emitter/
│   │       └── ApiClassesJsonEmitterTest.php
│   └── Integration/
│       ├── SmokeTest.php               # runs phpDocumentor against fixtures
│       ├── NexusSmokeTest.php          # runs against the 5 named Nexus classes
│       └── fixtures/
│           ├── SimpleGeneric.php       # class Foo<T>
│           ├── BoundedGeneric.php      # class Bar<T of Message>
│           ├── MultiParam.php          # class Baz<T, S>
│           ├── ExtendsGeneric.php      # class Qux extends Bar<Greet>
│           └── ImplementsGeneric.php   # class Quux implements Bar<Pong>
└── .github/
    └── workflows/
        ├── ci.yml                      # lint + unit + integration
        └── publish.yml                 # tags → packagist (deferred to post-V1)
```

---

## Pre-flight: Confirm sub-spec 0 is merged or available

Sub-spec 4a depends on sub-spec 0 (PHP 8.5.7 + Swoole 6.2.1 baseline) per spec §10. Two cases:

- **Sub-spec 0 merged to feat/nexus-doctrine (or main):** proceed. The plugin's Dockerfile pins `php:8.5.7-cli` independently — no actual git dependency.
- **Sub-spec 0 still in PR (current state):** also OK. The plugin's Dockerfile builds its own PHP 8.5.7 image; doesn't share the Nexus image. The only practical dependency is the smoke test (Task 9) which reads from `packages/nexus-core/src/` — and those files are identical on `feat/nexus-doctrine` (pre-plan-0) and on the merged branch (post-plan-0), since plan 0 only touched composer.json + Dockerfile, not PHP source.

**Verification before Task 1:**
```bash
ls /Users/tomas/Work/Monadial/CodeOSS/nexus/packages/nexus-core/src/Actor/ActorRef.php
# Exists on feat/nexus-doctrine ✓
```

---

## Task 1: Workspace + scaffolding

**Files:**
- Create: `phpdoc-templates-plugin/` (new git repo, sibling to nexus checkout)
- Create: `phpdoc-templates-plugin/.gitignore`, `.dockerignore`, `LICENSE`, `composer.json`, `Dockerfile`, `docker-compose.yml`, `Makefile`, `phpunit.xml`, `phpdoc.dist.xml`, `psalm.xml`, `phpcs.xml`

**Interfaces:**
- Consumes: nothing (entry task).
- Produces: a fully scaffolded plugin repo with Docker booting cleanly, composer install green, baseline `make test` (empty suite — no tests yet) green, baseline `make lint` (psalm + phpcs on empty src/) green.

- [ ] **Step 1: Create the plugin directory**

Use a sibling worktree under `.claude/worktrees/` for session isolation:

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus
mkdir -p .claude/worktrees/phpdoc-templates-plugin
cd .claude/worktrees/phpdoc-templates-plugin
git init --initial-branch=main
```

(Or use the `EnterWorktree` tool with `name=phpdoc-templates-plugin` if the runtime supports creating a non-worktree fresh git repo via that tool — otherwise the bash above is fine.)

- [ ] **Step 2: Write `.gitignore`**

```
/vendor/
/composer.lock
/build/
/.phpunit.cache/
/coverage/
*.swp
.DS_Store
```

- [ ] **Step 3: Write `composer.json`**

```json
{
    "name": "nexus-actors/phpdoc-templates-plugin",
    "description": "phpDocumentor v3 plugin: render @template/@template-extends/@template-implements/@template-covariant tags as first-class template parameters in class/method headers and signatures. Emits api-classes.json.",
    "type": "phpdocumentor-plugin",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "phpdocumentor/phpdocumentor": "^3.5",
        "phpdocumentor/reflection-docblock": "^5.4",
        "phpdocumentor/type-resolver": "^1.8"
    },
    "require-dev": {
        "phpunit/phpunit": "^13.0",
        "vimeo/psalm": "^6.0",
        "squizlabs/php_codesniffer": "^3.10",
        "slevomat/coding-standard": "^8.15"
    },
    "autoload": {
        "psr-4": {
            "NexusActors\\PhpdocTemplatesPlugin\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "NexusActors\\PhpdocTemplatesPlugin\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "phpdocumentor/*": true
        }
    }
}
```

- [ ] **Step 4: Write `Dockerfile`**

```dockerfile
FROM php:8.5.7-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        unzip git curl libicu-dev libzip-dev \
    && docker-php-ext-install intl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
```

- [ ] **Step 5: Write `docker-compose.yml`**

```yaml
services:
  phpdoc:
    build: .
    image: phpdoc-templates-plugin:dev
    volumes:
      - .:/app
      - ../../packages:/nexus-packages:ro  # the Nexus monorepo packages (read-only) for smoke tests
    working_dir: /app
    environment:
      - COMPOSER_HOME=/tmp/composer
```

- [ ] **Step 6: Write `Makefile`**

```makefile
.PHONY: build up install test lint smoke

build:
	docker compose build

up:
	docker compose up -d

install:
	docker compose exec -T phpdoc composer install --no-interaction

test:
	docker compose exec -T phpdoc vendor/bin/phpunit

lint:
	docker compose exec -T phpdoc vendor/bin/psalm
	docker compose exec -T phpdoc vendor/bin/phpcs src tests

smoke:
	docker compose exec -T phpdoc vendor/bin/phpdoc --config=phpdoc.dist.xml --force
```

- [ ] **Step 7: Write minimal `phpunit.xml`, `psalm.xml`, `phpcs.xml`**

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="integration"><directory>tests/Integration</directory></testsuite>
    </testsuites>
    <source><include><directory>src</directory></include></source>
</phpunit>
```

`psalm.xml`:
```xml
<?xml version="1.0"?>
<psalm errorLevel="1" resolveFromConfigFile="true"
       xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xmlns="https://getpsalm.org/schema/config">
    <projectFiles>
        <directory name="src" />
        <ignoreFiles><directory name="vendor" /></ignoreFiles>
    </projectFiles>
</psalm>
```

`phpcs.xml`:
```xml
<?xml version="1.0"?>
<ruleset name="plugin">
    <description>PER-CS2.0 + Slevomat</description>
    <rule ref="PSR12"/>
    <file>src</file>
    <file>tests</file>
</ruleset>
```

- [ ] **Step 8: Create empty `src/` and `tests/Unit/`, `tests/Integration/` directories**

```bash
mkdir -p src tests/Unit tests/Integration
touch src/.gitkeep tests/Unit/.gitkeep tests/Integration/.gitkeep
```

- [ ] **Step 9: Build + boot + install**

```bash
make build && make up && make install
```

Expected: composer install completes with no errors.

- [ ] **Step 10: Verify lint passes on empty source**

```bash
make lint
```

Expected: both psalm and phpcs report zero errors (empty src).

- [ ] **Step 11: Commit scaffolding**

```bash
git add -A
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(scaffold): initial repo + Docker + composer + lint setup

Scaffolds the nexus-actors/phpdoc-templates-plugin composer package.
Pins PHP 8.5.7 + phpDocumentor ^3.5 + Symfony EventDispatcher (transitive
via phpDocumentor). Sets up Docker-only dev environment, PHPUnit suite
shell, Psalm level 1, PHPCS PER-CS2.0.

No plugin logic yet — pure scaffolding so subsequent tasks land green
on a working baseline.

Hook + GPG signing bypassed (pre-approved): no GrumPHP hooks installed
yet; gpg-agent has no pinentry in this session.
EOF
)"
```

---

## Task 2: phpDocumentor extension-API discovery spike

**Files:**
- Create: `docs/notes/extension-api-spike.md` (within plugin repo) — captures findings.

**Interfaces:**
- Consumes: Task 1's working baseline.
- Produces: documented findings on (a) which phpDocumentor v3 extension point captures docblock tags, (b) how to register a Symfony service via plugin config, (c) which Twig template variables are available in `class.html.twig`. Subsequent tasks rely on the findings.

This is a discovery task. The plan can't pre-specify the exact API names because phpDocumentor v3's extension docs are sparse — the implementer reads source code and reports what's actually there.

- [ ] **Step 1: Inspect phpDocumentor v3's installed source**

```bash
docker compose exec -T phpdoc find vendor/phpdocumentor/phpdocumentor/src/phpDocumentor/Descriptor -name '*Assembler*' -o -name '*Builder*' | head
docker compose exec -T phpdoc find vendor/phpdocumentor/phpdocumentor/src/phpDocumentor -name 'Plugin*'
docker compose exec -T phpdoc grep -rn 'EventSubscriberInterface' vendor/phpdocumentor/phpdocumentor/src | head
```

Document: which classes/interfaces look like the right extension points?

- [ ] **Step 2: Inspect the default Twig templates phpDocumentor ships**

```bash
docker compose exec -T phpdoc find vendor/phpdocumentor -name 'class.html.twig'
docker compose exec -T phpdoc find vendor/phpdocumentor -name 'method.html.twig'
docker compose exec -T phpdoc grep -A5 'block content' $(docker compose exec -T phpdoc find vendor/phpdocumentor -name 'class.html.twig') | head -30
```

Document: where do template parameters need to render? What's the existing class-header structure?

- [ ] **Step 3: Verify `@template` is already parsed by type-resolver**

```bash
docker compose exec -T phpdoc php -r '
require "vendor/autoload.php";
$factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
$doc = $factory->create("/** @template T of \\Monadial\\Nexus\\Core\\Message */");
foreach ($doc->getTags() as $tag) {
    var_dump(get_class($tag), $tag);
}
'
```

Expected output: at least one Tag instance for the `@template` line. Document the class name and shape (what info can we extract: name, bound, variance).

- [ ] **Step 4: Write findings to `docs/notes/extension-api-spike.md`**

Document:
- Class names + namespaces for the descriptor extension point we'll subscribe to.
- Whether the existing `@template` parsing exposes the data we need, or if we need to add a custom Tag.
- The Twig template inheritance path (e.g. `templates/default/class.html.twig` overrides the upstream class.html.twig).
- Any gotchas / unknowns to escalate before Task 3.

- [ ] **Step 5: Commit findings**

```bash
git add docs/notes/extension-api-spike.md
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
docs(spike): document phpDocumentor v3 extension API for template tags

Spike output that sub-spec 4a's plan flagged as required before
implementing the plugin classes. Captures:
- Which descriptor-pipeline event subscriber attaches plugin data.
- Whether @template is already parsed by phpdocumentor/type-resolver.
- The Twig template-override mechanism (--template flag + override path).

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

**If the spike finds the extension API is NOT viable** (no public hook into descriptors, no Twig override path, type-resolver doesn't parse `@template`): STOP and report. The plan's fallback (per spec §8.4.2) is to post-process phpDocumentor's cached descriptor XML/JSON before Twig render. That fallback adds ~3 days and changes Tasks 3–6 — escalate to the controller before continuing.

---

## Task 3: `TemplateDescriptor` value object + tests

**Files:**
- Create: `src/Descriptor/TemplateDescriptor.php`
- Create: `tests/Unit/Descriptor/TemplateDescriptorTest.php`

**Interfaces:**
- Consumes: spike findings from Task 2.
- Produces: `TemplateDescriptor` value object with public properties `name: string`, `bound: ?string`, `variance: TemplateVariance` (enum: Invariant | Covariant | Contravariant). Subsequent tasks store arrays of these on class/method descriptors.

- [ ] **Step 1: Write the failing test**

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\Tests\Unit\Descriptor;

use NexusActors\PhpdocTemplatesPlugin\Descriptor\TemplateDescriptor;
use NexusActors\PhpdocTemplatesPlugin\Descriptor\TemplateVariance;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateDescriptor::class)]
final class TemplateDescriptorTest extends TestCase
{
    public function testUnboundedInvariantTemplate(): void
    {
        $t = new TemplateDescriptor('T', null, TemplateVariance::Invariant);
        self::assertSame('T', $t->name);
        self::assertNull($t->bound);
        self::assertSame(TemplateVariance::Invariant, $t->variance);
    }

    public function testBoundedTemplate(): void
    {
        $t = new TemplateDescriptor('T', 'Monadial\\Nexus\\Core\\Message', TemplateVariance::Invariant);
        self::assertSame('Monadial\\Nexus\\Core\\Message', $t->bound);
    }

    public function testCovariantTemplate(): void
    {
        $t = new TemplateDescriptor('R', null, TemplateVariance::Covariant);
        self::assertSame(TemplateVariance::Covariant, $t->variance);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
make test
```

Expected: 3 errors, all "class NexusActors\\…\\TemplateDescriptor not found".

- [ ] **Step 3: Write minimal implementation**

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\Descriptor;

final readonly class TemplateDescriptor
{
    public function __construct(
        public string $name,
        public ?string $bound,
        public TemplateVariance $variance,
    ) {
    }
}
```

And `src/Descriptor/TemplateVariance.php`:
```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\Descriptor;

enum TemplateVariance
{
    case Invariant;
    case Covariant;
    case Contravariant;
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
make test
```

Expected: 3 passing.

- [ ] **Step 5: Run lint**

```bash
make lint
```

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Descriptor tests/Unit/Descriptor
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
feat(plugin): add TemplateDescriptor value object + variance enum

Pure value object representing one parsed @template tag: name, optional
bound (FQCN), variance (Invariant/Covariant/Contravariant). 3 unit tests.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 4: `TemplateTagReader` — parse @template tags from a DocBlock

**Files:**
- Create: `src/Reflection/TemplateTagReader.php`
- Create: `tests/Unit/Reflection/TemplateTagReaderTest.php`

**Interfaces:**
- Consumes: `TemplateDescriptor` (Task 3).
- Produces: `TemplateTagReader::read(DocBlock $doc): array<TemplateDescriptor>` — extracts all `@template`, `@template-extends`, `@template-implements`, `@template-covariant`, `@template-contravariant` tags from a `phpDocumentor\Reflection\DocBlock` and returns an ordered array.

- [ ] **Step 1: Write failing tests**

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\Tests\Unit\Reflection;

use NexusActors\PhpdocTemplatesPlugin\Descriptor\TemplateVariance;
use NexusActors\PhpdocTemplatesPlugin\Reflection\TemplateTagReader;
use phpDocumentor\Reflection\DocBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateTagReader::class)]
final class TemplateTagReaderTest extends TestCase
{
    private TemplateTagReader $reader;
    private DocBlockFactory $factory;

    protected function setUp(): void
    {
        $this->reader = new TemplateTagReader();
        $this->factory = DocBlockFactory::createInstance();
    }

    public function testParsesUnboundedTemplate(): void
    {
        $doc = $this->factory->create('/** @template T */');
        $params = $this->reader->read($doc);

        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
        self::assertNull($params[0]->bound);
        self::assertSame(TemplateVariance::Invariant, $params[0]->variance);
    }

    public function testParsesBoundedTemplate(): void
    {
        $doc = $this->factory->create('/** @template T of \\Monadial\\Nexus\\Core\\Message */');
        $params = $this->reader->read($doc);

        self::assertCount(1, $params);
        self::assertSame('T', $params[0]->name);
        self::assertSame('\\Monadial\\Nexus\\Core\\Message', $params[0]->bound);
    }

    public function testParsesMultipleTemplates(): void
    {
        $doc = $this->factory->create("/**\n * @template T\n * @template S\n */");
        $params = $this->reader->read($doc);

        self::assertCount(2, $params);
        self::assertSame(['T', 'S'], array_map(fn ($p) => $p->name, $params));
    }

    public function testParsesCovariantTemplate(): void
    {
        $doc = $this->factory->create('/** @template-covariant R */');
        $params = $this->reader->read($doc);

        self::assertCount(1, $params);
        self::assertSame(TemplateVariance::Covariant, $params[0]->variance);
    }

    public function testNoTemplatesReturnsEmptyArray(): void
    {
        $doc = $this->factory->create('/** Just a description */');
        self::assertSame([], $this->reader->read($doc));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
make test
```

Expected: 5 errors, "class TemplateTagReader not found".

- [ ] **Step 3: Write minimal implementation**

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\Reflection;

use NexusActors\PhpdocTemplatesPlugin\Descriptor\TemplateDescriptor;
use NexusActors\PhpdocTemplatesPlugin\Descriptor\TemplateVariance;
use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Generic;

final readonly class TemplateTagReader
{
    /** @return list<TemplateDescriptor> */
    public function read(DocBlock $doc): array
    {
        $out = [];
        foreach ($doc->getTags() as $tag) {
            if (!$tag instanceof Generic) {
                continue;
            }
            $name = $tag->getName();
            $variance = match ($name) {
                'template', 'template-extends', 'template-implements' => TemplateVariance::Invariant,
                'template-covariant' => TemplateVariance::Covariant,
                'template-contravariant' => TemplateVariance::Contravariant,
                default => null,
            };
            if ($variance === null) {
                continue;
            }
            $desc = (string) $tag->getDescription();
            // shape: "T" or "T of \Fqcn"
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+of\s+(.+))?$/', trim($desc), $m)) {
                $out[] = new TemplateDescriptor($m[1], $m[2] ?? null, $variance);
            }
        }
        return $out;
    }
}
```

NOTE: This implementation uses `Generic` tag because type-resolver may or may not parse `@template` to a typed Tag class. The Task 2 spike's findings on `@template` parsing should inform whether this naive regex approach is enough or if a typed Tag class can be substituted.

- [ ] **Step 4: Run tests to verify they pass**

```bash
make test
```

Expected: 5 passing.

- [ ] **Step 5: Run lint**

```bash
make lint
```

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Reflection tests/Unit/Reflection
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
feat(plugin): add TemplateTagReader for @template* DocBlock tags

Parses @template, @template-extends, @template-implements,
@template-covariant, @template-contravariant tags from a
phpDocumentor\Reflection\DocBlock into TemplateDescriptor[]. 5 unit tests.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 5: Event subscriber wires reader → descriptor

**Files:**
- Create: `src/EventSubscriber/TemplateTagSubscriber.php`
- Create: `tests/Unit/EventSubscriber/TemplateTagSubscriberTest.php` (uses phpDocumentor's actual descriptor classes; minimal stub setup)

**Interfaces:**
- Consumes: `TemplateTagReader` (Task 4), spike findings (Task 2) for the exact event name + payload type.
- Produces: subscriber that, when phpDocumentor emits the "class descriptor assembled" event (exact name TBD from spike), reads the docblock's `@template*` tags and attaches them to the descriptor as a custom property accessible from Twig (`descriptor.template_parameters`).

**Note:** the exact event name + payload class come from the Task 2 spike. The implementer adjusts the class signature to match phpDocumentor's actual events. The shape below is illustrative.

- [ ] **Step 1: Write the failing test**

(Test stub — fill in based on spike findings)

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\Tests\Unit\EventSubscriber;

use NexusActors\PhpdocTemplatesPlugin\EventSubscriber\TemplateTagSubscriber;
use NexusActors\PhpdocTemplatesPlugin\Reflection\TemplateTagReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateTagSubscriber::class)]
final class TemplateTagSubscriberTest extends TestCase
{
    public function testGetSubscribedEventsListsTheClassAssemblyEvent(): void
    {
        $subscribed = TemplateTagSubscriber::getSubscribedEvents();
        // Exact event name TBD from Task 2 spike — implementer fills in.
        // Expected: at least one entry mapping a phpDocumentor descriptor-built event
        // to a method on this subscriber.
        self::assertNotEmpty($subscribed);
    }

    public function testAttachesTemplateParametersToDescriptor(): void
    {
        // Stub: synthesize a docblock + descriptor; invoke the subscriber's handler;
        // assert descriptor.template_parameters contains the expected TemplateDescriptor[].
        self::markTestIncomplete('Fill in once spike pins the descriptor class.');
    }
}
```

- [ ] **Step 2: Run test (1 should pass via stub, 1 incomplete)**

```bash
make test
```

Expected: 1 incomplete (`testAttachesTemplateParametersToDescriptor`), 1 passing (`testGetSubscribedEventsListsTheClassAssemblyEvent`) once the subscriber class exists. First run will fail with "class TemplateTagSubscriber not found".

- [ ] **Step 3: Write minimal implementation**

Filename: `src/EventSubscriber/TemplateTagSubscriber.php`

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\EventSubscriber;

use NexusActors\PhpdocTemplatesPlugin\Reflection\TemplateTagReader;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class TemplateTagSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TemplateTagReader $reader,
    ) {
    }

    /** @return array<string, string|array{0:string,1:int}> */
    public static function getSubscribedEvents(): array
    {
        // Exact event class FQCN comes from Task 2 spike.
        // Replace below with the real event name (e.g.
        // \phpDocumentor\Descriptor\…\PostAssembleEvent::class).
        return [
            // 'phpdocumentor.descriptor.class.post_assemble' => 'onClassAssembled',
        ];
    }

    public function onClassAssembled(object $event): void
    {
        // Implementation TBD from spike findings:
        //   - Get the descriptor from the event
        //   - Get the docblock from the descriptor
        //   - $params = $this->reader->read($docblock)
        //   - Attach: $descriptor->setCustom('template_parameters', $params)
    }
}
```

- [ ] **Step 4: Iterate until tests pass + spike-incomplete test is filled in**

The implementer fills in the event-name string + the handler body based on Task 2 spike findings. Re-runs `make test` until both tests pass.

- [ ] **Step 5: Run lint**

```bash
make lint
```

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/EventSubscriber tests/Unit/EventSubscriber
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
feat(plugin): event subscriber attaches @template* params to descriptors

Subscribes to phpDocumentor's class-descriptor-assembled event, parses
@template* tags from the docblock via TemplateTagReader, attaches the
resulting TemplateDescriptor[] to the descriptor's custom data slot
(accessible from Twig as descriptor.template_parameters).

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 6: Plugin entry class + Symfony service registration

**Files:**
- Create: `src/Plugin.php`
- Create: `src/ServiceDefinition.php` (or equivalent — exact mechanism from spike)
- Create: `tests/Integration/PluginLoadsTest.php` — integration test that boots phpDocumentor with the plugin and verifies it's registered

**Interfaces:**
- Consumes: `TemplateTagSubscriber` (Task 5).
- Produces: plugin entry that phpDocumentor loads via `phpdoc.dist.xml` `<plugin>` config; registers the subscriber as a Symfony service.

- [ ] **Step 1: Write failing integration test**

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PluginLoadsTest extends TestCase
{
    public function testPhpdocConfigLoadsPluginWithoutError(): void
    {
        $output = shell_exec('cd /app && vendor/bin/phpdoc --config=tests/Integration/fixtures/phpdoc.test.xml --force 2>&1');
        self::assertStringNotContainsString('error', strtolower($output ?? ''),
            'phpDocumentor should load the plugin without errors. Output: ' . $output);
        self::assertStringNotContainsString('exception', strtolower($output ?? ''));
    }
}
```

Plus a minimal fixture at `tests/Integration/fixtures/phpdoc.test.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpdocumentor configVersion="3">
    <paths>
        <output>build/api-test</output>
    </paths>
    <version number="1.0.0">
        <api>
            <source dsn="file://."><path>../</path></source>
        </api>
    </version>
    <plugin path="src/Plugin.php" />
</phpdocumentor>
```

- [ ] **Step 2: Run test to verify it fails**

```bash
make test
```

Expected: phpDocumentor either errors out (no plugin found) or runs without registering.

- [ ] **Step 3: Write minimal plugin entry**

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin;

use NexusActors\PhpdocTemplatesPlugin\EventSubscriber\TemplateTagSubscriber;
use NexusActors\PhpdocTemplatesPlugin\Reflection\TemplateTagReader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
// Exact phpDocumentor plugin interface name from Task 2 spike.

final class Plugin /* implements …PluginInterface */
{
    public function register(ContainerBuilder $container): void
    {
        $container->register(TemplateTagReader::class);
        $container->register(TemplateTagSubscriber::class)
            ->setArguments([new \Symfony\Component\DependencyInjection\Reference(TemplateTagReader::class)])
            ->addTag('kernel.event_subscriber');
    }
}
```

- [ ] **Step 4: Iterate until test passes**

Adjust the interface implementation + service-tag name based on Task 2 spike findings.

- [ ] **Step 5: Commit**

```bash
git add src/Plugin.php tests/Integration/PluginLoadsTest.php tests/Integration/fixtures/phpdoc.test.xml
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
feat(plugin): plugin entry + Symfony service wiring

Registers TemplateTagReader + TemplateTagSubscriber as Symfony services.
phpDocumentor loads the plugin via phpdoc.xml <plugin path="..."> config.
Integration test boots phpDocumentor with the plugin and verifies no
load/registration errors.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 7: Twig template overrides — class header + method signature

**Files:**
- Create: `src/Template/class.html.twig`
- Create: `src/Template/method.html.twig`
- Create: `src/Template/partials/template-params.html.twig`
- Create: `tests/Integration/fixtures/SimpleGeneric.php` (test class with `@template T`)
- Create: `tests/Integration/TemplateRenderingTest.php`

**Interfaces:**
- Consumes: descriptor's `template_parameters` (attached by subscriber in Task 5).
- Produces: rendered HTML where class `Foo` with `@template T` shows as `Foo<T>` in the page header, and methods bound with their own templates show as `method<R>(...)`.

- [ ] **Step 1: Write the failing integration test**

```php
<?php declare(strict_types=1);

namespace NexusActors\PhpdocTemplatesPlugin\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class TemplateRenderingTest extends TestCase
{
    public function testSimpleGenericClassRendersTemplateParam(): void
    {
        shell_exec('cd /app && vendor/bin/phpdoc --config=tests/Integration/fixtures/phpdoc.test.xml --force 2>&1');
        $html = file_get_contents('/app/build/api-test/classes/SimpleGeneric.html');
        self::assertNotFalse($html);
        self::assertMatchesRegularExpression('/SimpleGeneric\\s*&lt;\\s*T\\s*&gt;/', $html,
            'Class header should render template param T');
    }
}
```

Fixture:
```php
<?php declare(strict_types=1);
namespace NexusActors\PhpdocTemplatesPlugin\Tests\Integration\Fixtures;

/** @template T */
final class SimpleGeneric { }
```

- [ ] **Step 2: Run test to verify it fails**

Output won't contain `<T>` — the default templates don't know about `template_parameters`.

- [ ] **Step 3: Write the partial**

`src/Template/partials/template-params.html.twig`:

```twig
{# Render: <T1, T2 of Foo, …> #}
{% if params is iterable and params|length > 0 %}<span class="template-params">&lt;{% for p in params %}{{ p.name }}{% if p.bound %} of {{ p.bound }}{% endif %}{% if not loop.last %}, {% endif %}{% endfor %}&gt;</span>{% endif %}
```

- [ ] **Step 4: Write the class template override**

`src/Template/class.html.twig`:

```twig
{% extends "@default/class.html.twig" %}

{% block header %}
    {{ parent() }}
    {% if descriptor.custom.template_parameters is defined %}
        {{ include('@plugin/partials/template-params.html.twig', { params: descriptor.custom.template_parameters }) }}
    {% endif %}
{% endblock %}
```

(Exact block names + descriptor attribute path from Task 2 spike.)

- [ ] **Step 5: Wire the template override into phpdoc.xml**

Update `tests/Integration/fixtures/phpdoc.test.xml`:

```xml
<phpdocumentor configVersion="3">
    <paths>
        <output>build/api-test</output>
    </paths>
    <template name="default" />
    <template path="src/Template" />
    <version number="1.0.0">
        <api>...</api>
    </version>
    <plugin path="src/Plugin.php" />
</phpdocumentor>
```

- [ ] **Step 6: Iterate `make test` until regex matches**

If the rendered HTML uses different escaping or the template-params block doesn't render at all, iterate the partial.

- [ ] **Step 7: Write method.html.twig override**

Similar to class.html.twig — extends `@default/method.html.twig`, renders template params in the method signature where applicable.

Add a fixture test:

```php
<?php declare(strict_types=1);
namespace …\Fixtures;

/** @template T */
final class WithTemplatedMethod {
    /** @template R */
    public function transform(callable $fn): mixed { return null; }
}
```

Test: rendered HTML for `WithTemplatedMethod::transform` shows `transform<R>(…)`.

- [ ] **Step 8: Commit**

```bash
git add src/Template tests/Integration/fixtures tests/Integration/TemplateRenderingTest.php
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
feat(plugin): Twig overrides render template params in class + method

src/Template/class.html.twig extends the default class template to
render template parameters in the class header. method.html.twig does
the same for method signatures. Shared partial template-params.html.twig
handles the <T, S of Foo, …> formatting.

Integration tests: SimpleGeneric class renders <T>; WithTemplatedMethod
renders transform<R>(...).

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 8: `ApiClassesJsonEmitter` — write `api-classes.json` for sub-spec 6

**Files:**
- Create: `src/Emitter/ApiClassesJsonEmitter.php`
- Create: `tests/Unit/Emitter/ApiClassesJsonEmitterTest.php`

**Interfaces:**
- Consumes: phpDocumentor's full project descriptor (all classes that were rendered).
- Produces: writes `<output>/api-classes.json` after the render phase. JSON shape:

```json
[
  {"fqcn": "Monadial\\Nexus\\Core\\Actor\\ActorRef", "url": "classes/Monadial-Nexus-Core-Actor-ActorRef.html"},
  {"fqcn": "Monadial\\Nexus\\Core\\Actor\\Behavior", "url": "classes/Monadial-Nexus-Core-Actor-Behavior.html"},
  ...
]
```

(Sub-spec 6's class-name remark plugin reads this catalog at Docusaurus build time, per spec §8.5.)

- [ ] **Step 1: Write failing test**

```php
<?php declare(strict_types=1);
namespace NexusActors\PhpdocTemplatesPlugin\Tests\Unit\Emitter;

use NexusActors\PhpdocTemplatesPlugin\Emitter\ApiClassesJsonEmitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ApiClassesJsonEmitter::class)]
final class ApiClassesJsonEmitterTest extends TestCase
{
    public function testEmitsFlatCatalogOfFqcnAndUrl(): void
    {
        $emitter = new ApiClassesJsonEmitter();
        $tempFile = tempnam(sys_get_temp_dir(), 'apicls') . '.json';

        $entries = [
            ['fqcn' => 'Monadial\\Nexus\\Core\\Actor\\ActorRef', 'url' => 'classes/Monadial-Nexus-Core-Actor-ActorRef.html'],
            ['fqcn' => 'Monadial\\Nexus\\Core\\Actor\\Behavior', 'url' => 'classes/Monadial-Nexus-Core-Actor-Behavior.html'],
        ];
        $emitter->write($tempFile, $entries);

        $decoded = json_decode(file_get_contents($tempFile), true);
        self::assertCount(2, $decoded);
        self::assertSame('Monadial\\Nexus\\Core\\Actor\\ActorRef', $decoded[0]['fqcn']);

        unlink($tempFile);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

- [ ] **Step 3: Write minimal implementation**

```php
<?php declare(strict_types=1);
namespace NexusActors\PhpdocTemplatesPlugin\Emitter;

final readonly class ApiClassesJsonEmitter
{
    /** @param list<array{fqcn: string, url: string}> $entries */
    public function write(string $outputPath, array $entries): void
    {
        file_put_contents($outputPath, json_encode(
            $entries,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
```

- [ ] **Step 4: Run + green**

- [ ] **Step 5: Wire into the plugin** — add a second event subscriber that listens for phpDocumentor's "render complete" event and calls the emitter with the full class list. Exact event from Task 2 spike.

- [ ] **Step 6: Commit**

```bash
git add src/Emitter tests/Unit/Emitter
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
feat(plugin): emit api-classes.json (FQCN→URL catalog)

ApiClassesJsonEmitter writes a flat JSON array of {fqcn, url} pairs
after phpDocumentor's render phase completes. Sub-spec 6's class-name
remark plugin reads this catalog at Docusaurus build time to auto-link
inline `ClassName` references in narrative docs to api.nexusactors.com.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 9: Smoke test against the 5 named Nexus generic classes

**Files:**
- Create: `tests/Integration/NexusSmokeTest.php`
- Create: `tests/Integration/fixtures/phpdoc.nexus.xml` — phpDocumentor config that targets the Nexus monorepo's nexus-core + nexus-runtime packages

**Interfaces:**
- Consumes: all prior plugin code.
- Produces: a passing test that confirms ActorRef, Behavior, Props, BehaviorWithState, Future all render correctly with their template parameters.

- [ ] **Step 1: Write the failing test**

```php
<?php declare(strict_types=1);
namespace NexusActors\PhpdocTemplatesPlugin\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class NexusSmokeTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function genericClassProvider(): iterable
    {
        yield 'ActorRef<T>'           => ['Monadial-Nexus-Core-Actor-ActorRef.html',           'ActorRef'];
        yield 'Behavior<T>'           => ['Monadial-Nexus-Core-Actor-Behavior.html',           'Behavior'];
        yield 'Props<T>'              => ['Monadial-Nexus-Core-Actor-Props.html',              'Props'];
        yield 'BehaviorWithState<T,S>' => ['Monadial-Nexus-Core-Actor-BehaviorWithState.html', 'BehaviorWithState'];
        yield 'Future<T>'             => ['Monadial-Nexus-Runtime-Future.html',                'Future'];
    }

    /** @dataProvider genericClassProvider */
    public function testRendersTemplateParamsForGenericNexusClass(string $htmlPath, string $className): void
    {
        // Boot phpDocumentor once per test run (or use setUpBeforeClass for efficiency).
        if (!file_exists('/app/build/api-nexus')) {
            shell_exec('cd /app && vendor/bin/phpdoc --config=tests/Integration/fixtures/phpdoc.nexus.xml --force 2>&1');
        }
        $html = file_get_contents("/app/build/api-nexus/classes/{$htmlPath}");
        self::assertNotFalse($html, "Expected rendered HTML for {$className} at classes/{$htmlPath}");
        self::assertMatchesRegularExpression(
            "/{$className}\\s*&lt;\\s*[TRS](?:\\s*,\\s*[TRS])*\\s*&gt;/",
            $html,
            "Class {$className} should render its template parameters in the header"
        );
    }
}
```

- [ ] **Step 2: Write the Nexus phpdoc fixture config**

`tests/Integration/fixtures/phpdoc.nexus.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpdocumentor configVersion="3">
    <paths>
        <output>build/api-nexus</output>
        <cache>build/.cache</cache>
    </paths>
    <template name="default" />
    <template path="src/Template" />
    <version number="dev">
        <api>
            <source dsn="file:///nexus-packages">
                <path>nexus-core/src</path>
                <path>nexus-runtime/src</path>
            </source>
        </api>
    </version>
    <plugin path="src/Plugin.php" />
</phpdocumentor>
```

(Volume `/nexus-packages` is the read-only bind-mount of the Nexus monorepo's packages dir, declared in the plugin's docker-compose.yml in Task 1 Step 5.)

- [ ] **Step 3: Run the test**

```bash
make test
```

Expected first run: failures for each of the 5 classes if any template param doesn't render. Iterate plugin + Twig + spike findings until all 5 pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Integration/NexusSmokeTest.php tests/Integration/fixtures/phpdoc.nexus.xml
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
test(plugin): smoke-test 5 Nexus generic classes render template params

Verifies the 5 named acceptance classes from spec §8.1 deliverable 3:
ActorRef<T>, Behavior<T>, Props<T>, BehaviorWithState<T,S>, Future<T>.
Runs phpDocumentor against the live Nexus monorepo (bind-mounted at
/nexus-packages in this plugin's container) and asserts each class's
rendered HTML contains the literal template-parameter brackets.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 10: README + handoff docs

**Files:**
- Create: `README.md` (replaces the empty one from Task 1)
- Create: `docs/usage.md`
- Create: `docs/notes/extension-api-spike.md` (was already created in Task 2 — verify it's present)

**Interfaces:**
- Consumes: a fully working plugin.
- Produces: enough documentation for a phpDocumentor user to install + use the plugin + verify it works on their own project.

- [ ] **Step 1: Write `README.md`**

Sections:
- What the plugin does (1 paragraph)
- Why (Akka-grade `@template` rendering for Psalm-style generics)
- Install: `composer require --dev nexus-actors/phpdoc-templates-plugin`
- Configure phpdoc.xml: paste the `<plugin path="...">` + `<template path="..." />` snippets
- Smoke-verify: a 5-line example showing a `@template` class rendering with `<T>` after install
- License: MIT
- Status: stable in V1 (matches Nexus docs sub-spec 4a delivery)
- Link back to `github.com/nexus-actors/nexus` and `nexusactors.com`

- [ ] **Step 2: Write `docs/usage.md`**

Sections:
- Supported tags table (`@template`, `@template-extends`, `@template-implements`, `@template-covariant`, `@template-contravariant`)
- How rendering works in class header vs method signature
- How `api-classes.json` is generated and what consumes it
- Limitations: nested generics rendering depth, covariance/contravariance display style

- [ ] **Step 3: Commit**

```bash
git add README.md docs/usage.md
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
docs(plugin): write README + usage guide

End-of-V1 documentation. README covers install + configure + smoke-verify.
docs/usage.md catalogues supported tags + rendering rules + the
api-classes.json consumer contract for sub-spec 6.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 11: Final lint + test sweep + push handoff

**Files:** none modified — verification + git operations.

**Interfaces:**
- Consumes: all prior tasks.
- Produces: a clean repo on a remote branch (or a tarball) ready to be published to `github.com/nexus-actors/phpdoc-templates-plugin`.

- [ ] **Step 1: Full test suite**

```bash
make test
```

Expected: all unit + all integration tests pass (5 Nexus smoke tests + plugin-load + template-rendering + emitter tests).

- [ ] **Step 2: Full lint**

```bash
make lint
```

Expected: psalm + phpcs both clean.

- [ ] **Step 3: Decide publish path (controller-level decision)**

Two options, pick one with the controller before pushing:

- **Option A:** Push the repo as a new GitHub repo `github.com/nexus-actors/phpdoc-templates-plugin`. Tag `v0.1.0`. Submit to Packagist.
- **Option B:** Defer publishing. Keep the plugin local at `.claude/worktrees/phpdoc-templates-plugin/`. Sub-spec 4b's `phpdoc.dist.xml` references it via a composer path repository for now. Publishing to GitHub + Packagist deferred to post-V1.

The plan recommends Option B for V1 — defer publish until sub-spec 4b proves the plugin works end-to-end against the full Nexus surface.

- [ ] **Step 4: Verify the api-classes.json shape**

```bash
make smoke
cat build/api-nexus/api-classes.json | head -5
```

Expected: array of `{"fqcn":"...", "url":"..."}` objects. Validates against the schema sub-spec 6's remark plugin expects.

- [ ] **Step 5: Hand off to controller for execution-end summary**

Plan-4a is complete. Report:
- Plugin lives at `.claude/worktrees/phpdoc-templates-plugin/`
- N commits, all on `main` branch (local)
- All 5 smoke-test classes render template params
- `api-classes.json` populated and sub-spec-6-ready
- Publish strategy decided per Step 3

---

## Done

When all 11 tasks are checked off:

- Spec §8.1 deliverables 1, 2, 3 are met:
    1. Plugin reads all 5 `@template*` tag forms.
    2. Twig overrides render generics in class header + method signatures.
    3. Smoke test verifies 5 named classes (ActorRef, Behavior, Props, BehaviorWithState, Future).
- Spec §8.5 mechanism A (manual front-door links) + B (remark plugin via `api-classes.json`) infrastructure is in place; sub-spec 6 can consume `api-classes.json`.
- Sub-spec 4b unblocked: it can now run phpDocumentor with this plugin loaded against the full Nexus monorepo and ship to `api.nexusactors.com`.
