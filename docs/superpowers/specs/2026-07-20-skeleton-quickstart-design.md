# Skeleton Quickstart Experience — Design

Date: 2026-07-20
Status: approved (brainstorm with project owner)

## Problem

`composer create-project nexus-actors/skeleton` does not work for anyone outside the
monorepo: the package is not on Packagist, its `composer.json` carries monorepo path
repositories, and `bin/console` references maker commands that were never built. Meanwhile
the landing site funnels its highest-value nav slot to a web "Bootstrap" wizard that
generates config for a flow nobody can actually run. The homepage also lacks any cluster
presence despite the cluster-tcp feature page shipping.

## Goals

1. `composer create-project nexus-actors/skeleton my-app --stability=dev` works end to end
   for an external user and lands them in a runnable actor project within minutes.
2. An interactive CLI wizard (full module parity with the removed web wizard, experimental
   modules labeled) configures the project at create time and remains re-runnable.
3. `make:actor` / `make:message` generators exist (deferred "Plan 2" from the skeleton
   foundation work).
4. The landing replaces Bootstrap with a `/quickstart` page teaching the real flow, and the
   homepage gains a cluster showcase section.

Non-goals: Composer-plugin/Flex-style recipes; multiple skeleton variants; stable-version
tagging (blocked by the release gate — internal constraints are `dev-main`).

## 1. Skeleton overhaul (`packages/nexus-skeleton`)

- Remove path-repository entries from `composer.json`. A helper script at
  `bin/link-monorepo` injects them locally for monorepo CI testing.
- Keep `type: project`, `App\` PSR-4, `dev-main` internal deps; add
  `"minimum-stability": "dev"` and `"prefer-stable": true` so `--stability=dev` resolves.
- Add README with the 3-command quickstart; copy `.env.example` to `.env` on install
  (composer script). Remove committed `composer.lock`, `.phpunit.result.cache`, and `.env`
  from the template (gitignore them).
- `src/Command/SetupCommand.php` (`nexus:setup`), wired to `post-create-project-cmd` and
  registered in `bin/console`; re-runnable to add modules later.

### Wizard questions (in order)

1. Runtime: Fiber (default) / Swoole — Swoole choice checks `ext-swoole` >= 6.2.1 and
   warns if absent.
2. HTTP server: yes/no — offered only when Swoole selected; on Fiber prints a note.
3. Persistence (experimental): none / in-memory / DBAL / Doctrine ORM.
4. Observability: none / OpenTelemetry.
5. TCP cluster (experimental): yes/no.
6. Messenger bridge (experimental): yes/no.

Each answer maps to a static recipe: `composer require` package list, a
`config/packages/<module>.php` template, and doc links printed at completion. Experimental
selections print a warning matching the StabilityMatrix wording ("experimental, not
production-ready; APIs may change"). Wizard exit prints next steps: `bin/console
make:actor`, `bin/console run`. `--no-interaction` takes all defaults (Fiber, no modules).

The `Kernel` is unchanged; recipes only add files under `config/packages/` which it
already loads.

## 2. Maker package (`packages/nexus-maker`, new, require-dev)

- Namespace `Nexus\Maker`, entry `MakerCommands::all(string $projectDir)` — the exact hook
  `bin/console` already calls.
- `make:actor Name` — generates `src/Actor/NameActor.php` with `#[AsActor]`,
  `ActorHandler`, match-based handler; `--with-message` also generates the paired message.
- `make:message Name` — generates a `readonly class` with `#[MessageType]`.
- Templates mirror the skeleton's `GreeterActor` / `Greet` style. Generators refuse to
  overwrite existing files.
- Runtime deps: none (symfony/console provided by the consuming app). Joins Deptrac and
  `split.yml`.

## 3. Landing `/quickstart` (replaces Bootstrap)

- Delete `landing/src/pages/bootstrap.astro`, `landing/src/components/BootstrapWizard.tsx`,
  `landing/src/lib/bootstrapConfig.ts`; remove all references (Nav, MobileNav, any CTAs
  pointing at /bootstrap get retargeted to /quickstart).
- Nav's highlighted button becomes "Quickstart" -> `/quickstart`.
- `/quickstart` is fully static (no React island): terminal-styled blocks telling one
  linear story — `create-project` command, wizard session transcript, `make:actor`,
  `bin/console run` output, resulting file tree, links to docs quick-start and module
  pages. Uses the `--stability=dev` form of the command until stable tags exist.

## 4. Homepage cluster showcase

- New `landing/src/components/ClusterShowcase.astro` following the
  HttpShowcase/PersistenceShowcase/ObservabilityShowcase pattern: prose left ("Actors
  across machines", experimental-labeled), `CodeBlock` right with the `ClusterNode::boot`
  + `ClusterRef` snippet from `/cluster`, CTA to `/cluster`.
- Inserted after `ObservabilityShowcase` in `index.astro`.

## 5. Publishing and infra

- Add `nexus-skeleton` and `nexus-maker` to the `split.yml` matrix (37 -> 39 entries);
  update the release-process doc counts.
- Manual handoff (project owner): register `nexus-actors/skeleton` and
  `nexus-actors/maker` on Packagist once split repos exist.
- CI: new job validates create-project from the local path (path repos injected), runs
  `nexus:setup --no-interaction`, then `bin/console run` smoke test.

## 6. Testing

- `SetupCommandTest` via CommandTester: each recipe writes expected config +
  composer.json changes; experimental warnings printed; `--no-interaction` defaults.
- Existing `KernelBootTest` / `AsActorPassTest` stay green.
- Maker: generated files pass `php -l`, match expected templates, overwrite refused.
- End-to-end CI job as above.
- Landing: build passes, `/quickstart` renders, zero remaining references to bootstrap
  (grep gate), homepage renders ClusterShowcase.

## Decisions log

- Approach: interactive `nexus:setup` console command triggered by
  `post-create-project-cmd` (chosen over Flex-style composer plugin and static skeleton
  variants).
- Maker lives in a separate require-dev package, not inside the skeleton.
- Wizard offers full module parity with the removed web wizard, experimental modules
  labeled (owner choice).
- Landing gets a dedicated `/quickstart` page (owner choice).
