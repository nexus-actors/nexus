---
title: Adding a Package
related:
  - contributing/release-process
  - contributing/splitsh
  - contributing/your-first-pr
---

# Adding a Package

Adding a new package to the Nexus monorepo requires updating multiple files. This checklist covers every location that must change. Missing any one of them will result in a broken CI pipeline, failed splits, or missing Packagist publication.

The monorepo currently has **41 packages** under `packages/`:

`nexus`, `nexus-app`, `nexus-cluster`, `nexus-core`, `nexus-doctrine-dbal`, `nexus-doctrine-orm`, `nexus-http`, `nexus-http-auth`, `nexus-http-server-swoole`, `nexus-http-server-swoole-threads`, `nexus-http-toolkit`, `nexus-http-ws`, `nexus-logger`, `nexus-persistence`, `nexus-persistence-dbal`, `nexus-persistence-doctrine`, `nexus-psalm`, `nexus-runtime`, `nexus-runtime-fiber`, `nexus-runtime-step`, `nexus-runtime-swoole`, `nexus-serialization`, `nexus-worker-pool`, `nexus-worker-pool-swoole`.

## Checklist

### 1. Create the package directory

```bash title="terminal"
mkdir -p packages/nexus-my-package/{src,tests}
```

Create the minimum required files:

```json title="packages/nexus-my-package/composer.json"
{
    "name": "nexus-actors/my-package",
    "description": "One sentence describing the package.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.5.7",
        "nexus-actors/core": "^0.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^12.1"
    },
    "autoload": {
        "psr-4": {
            "Monadial\\Nexus\\MyPackage\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Monadial\\Nexus\\MyPackage\\Tests\\": "tests/"
        }
    }
}
```

Create `packages/nexus-my-package/README.md` with a one-paragraph description.

### 2. Add to root `composer.json`

Add a path repository entry and a `require` (or `require-dev`) entry:

```json title="composer.json (root) — add to repositories array"
{
    "type": "path",
    "url": "packages/nexus-my-package"
}
```

```json title="composer.json (root) — add to require or require-dev"
"nexus-actors/my-package": "^0.1"
```

Run `make install` after editing to regenerate `composer.lock`.

### 3. Update affected `packages/*/composer.json` files

If any existing package depends on your new package, add it to that package's `require` section. There are 41 `composer.json` files total — check each one that is logically related to your package's functionality.

### 4. Update `deptrac.yaml`

Add a new layer for your package so Deptrac can enforce import boundaries:

```yaml title="deptrac.yaml"
layers:
  - name: MyPackage
    collectors:
      - type: className
        regex: ^Monadial\\Nexus\\MyPackage\\.*
```

Add allowable dependencies to the `ruleset` section. If your package depends on `nexus-core`:

```yaml title="deptrac.yaml"
ruleset:
  MyPackage:
    - Core
```

Run `make deptrac` to verify the new rules are valid.

:::warning Keep the ruleset direction honest
A package's Deptrac ruleset must list **only** the layers it actually imports — never more. A rule that is broader than the package's `composer.json` (for example allowing `Runtime -> Core` when `nexus-runtime` declares no `nexus-actors/core` dependency and imports no Core symbol) passes monorepo boundary analysis but breaks a standalone split install, since the dependency it silently relies on is absent from the published package. Boundary direction is one-way: `nexus-core` consumes `nexus-runtime` primitives (`Duration`, `Mailbox`), so `Core -> Runtime` is allowed while `Runtime -> Core` is forbidden. `bin/verify-runtime-core-boundary.php` (CI step *Runtime→Core boundary fixture*, or `make boundary-check`) guards that specific edge by injecting an intentional violation and asserting both Deptrac and `bin/check-package-deps.php` reject it.
:::

### 5. Update `.github/workflows/split.yml`

Add your package to the split matrix:

```yaml title=".github/workflows/split.yml"
- { local: 'nexus-my-package', remote: 'my-package' }
```

Create the `nexus-actors/my-package` repository on GitHub before your first push, otherwise the split step will fail with a 404.

### 6. Update `website/docs/intro.md`

Add a row to the packages table in the introduction page. Include: package name (composer format), a one-sentence description, and a link to the dedicated package doc page.

### 7. Add `website/docs/packages/my-package.md`

Create a package reference page. Follow the pattern of existing package pages:

- Frontmatter with `title` and `related` keys
- One-sentence synopsis as the opening paragraph
- Install: `` `composer require nexus-actors/my-package` ``
- Key classes / interfaces table
- Minimal usage example with a code block title
- See also links

Add the page to `website/sidebars.js` under the appropriate Packages sub-category.

## Summary of files to touch

| File | Change |
|---|---|
| `packages/nexus-my-package/` | Create directory with `src/`, `tests/`, `composer.json`, `README.md` |
| `composer.json` (root) | Add path repository + `require`/`require-dev` entry |
| `packages/*/composer.json` | Add dependency where needed (up to 41 files) |
| `deptrac.yaml` | Add layer + ruleset entries |
| `.github/workflows/split.yml` | Add matrix entry |
| `website/docs/intro.md` | Add row to packages table |
| `website/docs/packages/my-package.md` | Create doc page |
| `website/sidebars.js` | Add sidebar entry |

## See also

- [Release process](release-process.md) — how the split runs and when packages are published
- [splitsh internals](splitsh.md) — debugging split failures
