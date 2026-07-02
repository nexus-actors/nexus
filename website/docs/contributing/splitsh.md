---
title: splitsh-lite Internals
related:
  - contributing/release-process
  - contributing/adding-a-package
---

# splitsh-lite Internals

This page explains how splitsh-lite works, when to use it, and how to debug a split failure.

## What splitsh-lite does

splitsh-lite reads a git repository's full history and produces a single commit SHA that represents the subtree at a given prefix. The operation is equivalent to `git subtree split` but is implemented in Go and is dramatically faster on large histories.

For a monorepo with history like:

```
abc123  feat(core): add ActorSystem::shutdown deadline
def456  fix(http): return 401 for missing credentials
ghi789  docs(persistence): clarify snapshot retention
```

Running `splitsh-lite --prefix=packages/nexus-core` produces a new SHA whose tree contains only the files under `packages/nexus-core/` and whose history contains only commits that touched those files. That SHA is then pushed to the `nexus-actors/core` split repository.

## Why not git-subtree or git-subrepo

`git subtree` is built in to git but is slow on large histories and adds merge commits. `git subrepo` requires installation and has different semantics. splitsh-lite is a stateless binary: it takes the full monorepo history on every run and deterministically produces the same SHA for the same input — no state to corrupt, no merge commits to undo.

## Test a split locally

Install splitsh-lite:

```bash title="terminal"
curl -sL https://github.com/splitsh/lite/releases/download/v1.0.1/lite_linux_amd64.tar.gz \
  | tar xz
sudo mv splitsh-lite /usr/local/bin/
```

Run a dry split for any package:

```bash title="terminal"
# From the monorepo root
SHA=$(splitsh-lite --prefix=packages/nexus-core)
echo "Resulting SHA: $SHA"

# Inspect the split tree
git log "$SHA" --oneline | head -20
git show "$SHA:." | head -30
```

The `$SHA` is a commit in the monorepo's own object store — it is not pushed anywhere. You can create a local branch from it to inspect the result:

```bash title="terminal"
git checkout -b inspect-core-split "$SHA"
ls   # Should show only nexus-core's files
git log --oneline | head -10
```

## Debugging a split failure

**The split push fails with 403.** The `SPLIT_TOKEN` secret is missing or expired. Regenerate the token on GitHub, update the secret in the monorepo's Actions settings, and re-run the workflow.

**The split push fails with "non-fast-forward".** The split repo has diverged — this happens if commits were pushed directly to the split repo (never do this). Reset the split repo's main branch to the new SHA:

```bash title="terminal"
# DANGER: force-pushes to the split repo
SHA=$(splitsh-lite --prefix=packages/nexus-core)
git remote add target "https://x-access-token:${SPLIT_TOKEN}@github.com/nexus-actors/core.git"
git push target "${SHA}:refs/heads/main" -f
```

**splitsh-lite produces an empty commit.** The prefix path is wrong. Verify the directory exists in the monorepo root:

```bash title="terminal"
ls packages/nexus-core/
```

**A new package is not being split.** The package entry is missing from `split.yml`'s matrix. See [adding a package](adding-a-package.md) for the exact checklist.

## Adding a split target

When adding a new package, add one entry to the `matrix.package` list in `.github/workflows/split.yml`:

```yaml title=".github/workflows/split.yml"
- { local: 'nexus-my-package', remote: 'my-package' }
```

`local` is the directory name under `packages/`; `remote` is the GitHub repository name under `nexus-actors/`. Create the target repository on GitHub before pushing, otherwise the push step fails.

## See also

- [Release process](release-process.md) — when splits run and versioning conventions
- [Adding a package](adding-a-package.md) — full checklist for new packages
