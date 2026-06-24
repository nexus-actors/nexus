---
title: Release Process
related:
  - contributing/splitsh
  - contributing/your-first-pr
  - contributing/adding-a-package
---

# Release Process

Nexus is a monorepo with 14 packages under `packages/`. Each package is mirrored to its own GitHub repository and published to Packagist independently. The split-and-publish pipeline is implemented in `.github/workflows/split.yml`.

## How splits work

The pipeline uses [splitsh-lite](https://github.com/splitsh/lite) — a Go binary that extracts a sub-directory's git history into a standalone SHA. For each package:

1. `splitsh-lite --prefix=packages/<local>` reads the monorepo's full history and outputs a single commit SHA that represents only that package's subtree.
2. The SHA is pushed to the corresponding `nexus-actors/<remote>` repository on GitHub (e.g., `nexus-actors/core`, `nexus-actors/runtime-fiber`).

The split runs in a matrix job, one job per package, with `fail-fast: false` so a failure in one package does not block others.

## Package mapping

| Monorepo directory | Split target repository |
|---|---|
| `packages/nexus-core` | `nexus-actors/core` |
| `packages/nexus-serialization` | `nexus-actors/serialization` |
| `packages/nexus-runtime` | `nexus-actors/runtime` |
| `packages/nexus-runtime-fiber` | `nexus-actors/runtime-fiber` |
| `packages/nexus-runtime-swoole` | `nexus-actors/runtime-swoole` |
| `packages/nexus-runtime-step` | `nexus-actors/runtime-step` |
| `packages/nexus-psalm` | `nexus-actors/psalm` |
| `packages/nexus-persistence` | `nexus-actors/persistence` |
| `packages/nexus-persistence-dbal` | `nexus-actors/persistence-dbal` |
| `packages/nexus-persistence-doctrine` | `nexus-actors/persistence-doctrine` |
| `packages/nexus-app` | `nexus-actors/app` |
| `packages/nexus-cluster` | `nexus-actors/cluster` |
| `packages/nexus-worker-pool` | `nexus-actors/worker-pool` |
| `packages/nexus-worker-pool-swoole` | `nexus-actors/worker-pool-swoole` |

## When the split runs

The workflow triggers on three events:

| Trigger | What happens |
|---|---|
| Push to `main` after CI passes | Splits the `main` branch tip to each split repo |
| Tag push (`v*`) | Splits and pushes the same tag to each split repo |
| `workflow_dispatch` | Manual re-run (useful after fixing a split failure) |

The tag-push path is what Packagist uses: when you push `v1.0.0` to the monorepo, the workflow pushes that tag to all 14 split repos, and Packagist picks up the new version within minutes.

## Versioning convention

Nexus follows [Semantic Versioning](https://semver.org/). All packages share the same version number — a `v1.2.0` tag in the monorepo becomes `v1.2.0` in every split repo simultaneously. Cross-package constraints use `^1.0` (allow minor and patch upgrades). Never release packages at different version numbers.

To cut a release:

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
