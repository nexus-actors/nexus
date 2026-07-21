---
title: Release Process
related:
  - contributing/splitsh
  - contributing/your-first-pr
  - contributing/adding-a-package
---

# Release Process

Nexus is a monorepo with 41 package directories under `packages/`. Split packages are mirrored to their own GitHub repositories and published to Packagist independently. The split-and-publish pipeline is implemented in `.github/workflows/split.yml`, whose matrix currently lists all 41 packages (the `nexus` meta-package splits to the `nexus-actors/meta` repository).

## How splits work

The pipeline uses [splitsh-lite](https://github.com/splitsh/lite) — a Go binary that extracts a sub-directory's git history into a standalone SHA. For each package:

1. `splitsh-lite --prefix=packages/<local>` reads the monorepo's full history and outputs a single commit SHA that represents only that package's subtree.
2. The SHA is pushed to the corresponding `nexus-actors/<remote>` repository on GitHub (e.g., `nexus-actors/core`, `nexus-actors/runtime-fiber`).

The split runs in a matrix job, one job per package, with `fail-fast: false` so a failure in one package does not block others.

## Package mapping

Every matrix entry follows the same rule: `packages/nexus-<name>` splits to the `nexus-actors/<name>` repository (e.g., `packages/nexus-core` to `nexus-actors/core`, `packages/nexus-worker-pool-swoole` to `nexus-actors/worker-pool-swoole`). The authoritative list is the `matrix.package` block in `.github/workflows/split.yml` — 41 entries at the time of writing. When you add a package, add its matrix entry there (see [Adding a package](adding-a-package.md)).

## When the split runs

The workflow triggers on three events:

| Trigger | What happens |
|---|---|
| Push to `main` after CI passes | Splits the `main` branch tip to each split repo |
| Tag push (`v*`) | Splits and pushes the same tag to each split repo |
| `workflow_dispatch` | Manual re-run (useful after fixing a split failure) |

The tag-push path is what Packagist uses: when you push `v1.0.0` to the monorepo, the workflow pushes that tag to all 41 split repos, and Packagist picks up the new version within minutes.

## Versioning convention

Nexus follows [Semantic Versioning](https://semver.org/). All packages share the same version number — a `v1.2.0` tag in the monorepo becomes `v1.2.0` in every split repo simultaneously. Never release packages at different version numbers.

:::danger Release gate — internal constraints are not yet versioned
Cross-package constraints in the package manifests currently use `dev-main`, not versioned constraints (for example, `packages/nexus-app/composer.json` requires `nexus-actors/core: dev-main`). The split workflow copies manifests verbatim — it does not rewrite constraints when tagging. A tagged split package would therefore still depend on the moving `dev-main` branch heads of its siblings, and stable consumers (default `minimum-stability: stable`) cannot resolve it.

**No stable release should be cut from the current manifests.** Before tagging any `v*` release, the internal `dev-main` constraints must be replaced with release-compatible versioned constraints (e.g., `self.version` or `^1.0`), and every split package should be installed from a clean fixture at stable stability to prove it resolves.
:::

To cut a release (once the constraints above are versioned):

```bash title="terminal"
git tag v1.2.0
git push origin v1.2.0
```

CI must be green on `main` before tagging. The split workflow verifies this via the `workflow_run` trigger's `conclusion == 'success'` gate.

## Required secret

The workflow uses `SPLIT_TOKEN` — a GitHub personal access token with `repo` scope on all `nexus-actors/*` split repositories. This secret is stored in the monorepo's GitHub Actions secrets. Without it the push steps will fail with 403.

## Manual re-run

If a split job fails (e.g., network error pushing to GitHub), re-run it via:

```bash title="terminal"
gh workflow run split.yml
```

Or navigate to Actions → Split Packages → Re-run jobs in the GitHub UI.

## See also

- [splitsh internals](splitsh.md) — lower-level explanation of splitsh-lite
- [Adding a package](adding-a-package.md) — how to add a new package including the split.yml entry
