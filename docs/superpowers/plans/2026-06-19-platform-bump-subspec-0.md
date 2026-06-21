# Platform Bump — Sub-spec 0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the Nexus monorepo's platform baseline to PHP 8.5.7 + Swoole 6.2.1 (stable, not RC), normalize the `php` composer constraint across every package, and add a Swoole-version floor on the four Swoole-dependent packages so consumers fail-fast on old Swoole.

**Architecture:** Configuration changes only — no PHP source code is modified. Edits land in `docker/Dockerfile` (2 changes) and 24 `composer.json` files (4 of them also get an `ext-swoole` constraint). Verification is "all existing tests still green on the bumped baseline" + "CI matrix passes."

**Tech Stack:** Docker (php 8.5-cli / php 8.5-zts base images), Swoole 6.2.1, Composer, PHPUnit, Psalm, PHPCS, PHP-CS-Fixer, GrumPHP pre-commit hooks, GitHub Actions CI.

## Global Constraints

Every task in this plan must honor these (from spec §2 and CLAUDE.md):

- **Docker for everything.** No host PHP. All commands run via `docker compose exec php …` or `make` targets that wrap Docker. Never invoke `php`, `composer`, or `vendor/bin/*` on the host.
- **Pre-commit hooks via Docker.** GrumPHP runs 4 checks (PHP-CS-Fixer, PHPCS, Psalm, PHPUnit) through `docker compose exec -T php`. All four must pass before each commit.
- **No `Co-Authored-By: Claude` trailer on commits.** Project rule.
- **New commits only, no `--amend`.** If a pre-commit hook fails, fix the issue, re-stage, create a new commit.
- **Never `--no-verify` or `--no-gpg-sign`** unless explicitly approved per-commit by the user.
- **Conventional commit prefixes** (matching recent git log): `chore(swoole):`, `chore(deps):`, `ci(matrix):`, `fix(docker):`. Use `chore(platform):` as the umbrella prefix for this plan's commits.
- **24 packages total** in the launch baseline (`main + feat/nexus-doctrine` merged): `nexus` (umbrella, no `src/`) + 23 packages with `src/`. If running on `main` alone before the merge: 16 packages — same plan applies but only to those 16 + 2 Swoole packages (not 4).
- **No new features, no refactors, no opportunistic cleanups.** Only the deltas spelled out in this plan.

---

## File Structure

**Files modified:**

- `docker/Dockerfile` (3 lines changed: 2 FROM lines + 1 git clone branch)
- `composer.json` (root) — `php` constraint normalized
- 23 `packages/*/composer.json` — `php` constraint normalized; 4 of them also get `ext-swoole` constraint:
    - `packages/nexus-runtime-swoole/composer.json`
    - `packages/nexus-worker-pool-swoole/composer.json`
    - `packages/nexus-http-server-swoole/composer.json` (only if `feat/nexus-doctrine` is merged)
    - `packages/nexus-http-server-swoole-threads/composer.json` (only if `feat/nexus-doctrine` is merged)
- (no source code, no tests, no docs are modified)

**Files NOT modified:**

- `.github/workflows/ci.yml` — already PHP-agnostic; uses the Dockerfile's `target:` to build images, so it inherits the bumped baseline automatically. No edits needed.
- `phpunit.xml`, `psalm.xml`, `phpcs.xml`, `infection.json5`, `deptrac.yaml`, `grumphp.yml` — no version-specific knobs that would need bumping.

---

## Pre-flight: Determine the active integration branch

The sub-spec lands on whatever branch will be merged to `main` for promotion. Today the active branch is `feat/nexus-doctrine`. Verify before starting.

```bash
git -C /Users/tomas/Work/Monadial/CodeOSS/nexus branch --show-current
# Expected: feat/nexus-doctrine (or main if already merged)
ls /Users/tomas/Work/Monadial/CodeOSS/nexus/packages | wc -l
# Expected: 24 (post-merge) or 16 (pre-merge — fewer Swoole packages)
```

If the result is `feat/nexus-doctrine` with 24 packages: this plan's 4-package `ext-swoole` list is correct.
If the result is `main` with 16 packages: the `ext-swoole` list collapses to 2 packages (nexus-runtime-swoole, nexus-worker-pool-swoole) — adjust Task 3 accordingly.

---

## Task 1: Workspace setup + green baseline

**Files:**
- No file edits in this task.

**Interfaces:**
- Consumes: nothing (entry task).
- Produces: a fresh worktree at `.claude/worktrees/platform-bump` on a new branch `platform-bump-0`, branched from the active integration branch, with `vendor/` installed and the full test suite verified green BEFORE any changes are made.

**Goal of task:** establish that the baseline is green so any failures in later tasks are attributable to the bump, not pre-existing breakage.

- [ ] **Step 1: Confirm starting point**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus
git status              # expect clean working tree
git branch --show-current   # note the active integration branch
ls packages | wc -l     # confirm 24 (post-merge) or 16 (pre-merge)
```

- [ ] **Step 2: Create the worktree**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus
git worktree add .claude/worktrees/platform-bump -b platform-bump-0
cd .claude/worktrees/platform-bump
```

(Or use the `EnterWorktree` tool with `name=platform-bump` if available in the runtime — it produces the same result.)

- [ ] **Step 3: Boot Docker + install composer dependencies**

```bash
make build      # builds php, php-fiber, php-swoole images
make up         # starts containers
make install    # composer install inside php container
```

Expected: clean composer install completes; `Created git hooks folder` line visible in output.

- [ ] **Step 4: Run baseline tests — they MUST pass before any changes**

```bash
make test
```

Expected: all suites pass (unit + Fiber integration + Swoole integration + persistence + serialization). If any baseline test fails, STOP and report — the failure pre-dates this plan.

- [ ] **Step 5: Run baseline static analysis**

```bash
make psalm
make phpcs
make cs
```

Expected: all three clean. Same rule — if any fail before changes, STOP and report.

- [ ] **Step 6: Record baseline output**

```bash
make test 2>&1 | tail -20 > /tmp/baseline-test-output.txt
make psalm 2>&1 | tail -5 > /tmp/baseline-psalm-output.txt
echo "Baseline recorded at $(date)"
```

Keep these around to diff against post-change results. No commit in this task — baseline only.

---

## Task 2: Bump Swoole RC → stable in Dockerfile

**Files:**
- Modify: `docker/Dockerfile:71` — change `v6.2.0-rc1` to `v6.2.1`.

**Interfaces:**
- Consumes: green baseline from Task 1.
- Produces: rebuilt Docker images with Swoole 6.2.1 stable; later tasks rely on this baseline being present in the running containers.

- [ ] **Step 1: Verify the current line**

```bash
grep -n "v6.2.0-rc1" docker/Dockerfile
```

Expected:
```
71:    && git clone --depth 1 --branch v6.2.0-rc1 https://github.com/swoole/swoole-src.git \
```

- [ ] **Step 2: Edit the Dockerfile**

Change line 71 from:
```dockerfile
    && git clone --depth 1 --branch v6.2.0-rc1 https://github.com/swoole/swoole-src.git \
```
to:
```dockerfile
    && git clone --depth 1 --branch v6.2.1 https://github.com/swoole/swoole-src.git \
```

- [ ] **Step 3: Confirm no other Swoole version references exist**

```bash
grep -rn "6.2.0\|6.2.0-rc" docker/
```

Expected: zero matches in `docker/`.

- [ ] **Step 4: Rebuild the Docker images**

```bash
make down       # stop containers
make build      # rebuild (forces Swoole rebuild stage)
make up         # restart containers
```

Expected: build completes; `swoole-build` stage clones `v6.2.1` (visible in build log).

- [ ] **Step 5: Verify Swoole 6.2.1 is installed inside the container**

```bash
docker compose exec php-swoole php -r 'echo SWOOLE_VERSION, PHP_EOL;'
```

Expected: `6.2.1`

```bash
docker compose exec php-swoole php -m | grep -i swoole
```

Expected: `swoole` listed in active modules.

- [ ] **Step 6: Run the Swoole + worker-pool test suites**

```bash
make test-swoole
make test-cluster
```

Expected: both pass. If anything new fails vs Task 1 baseline, the Swoole RC→stable bump caused a regression — diagnose before continuing.

- [ ] **Step 7: Commit**

```bash
git add docker/Dockerfile
git commit -m "chore(platform): bump Swoole v6.2.0-rc1 → v6.2.1 stable

Sub-spec 0 deliverable item 1. Docker swoole-build stage now clones the
v6.2.1 stable release (verified upstream: github.com/swoole/swoole-src
releases/tag/v6.2.1) instead of the prior 6.2.0-rc1 release-candidate.

Verification: SWOOLE_VERSION == '6.2.1' inside php-swoole container;
make test-swoole and make test-cluster pass."
```

(Pre-commit hook runs GrumPHP via Docker — expect it to pass since no PHP source changed.)

---

## Task 3: Pin PHP base image to 8.5.7

**Files:**
- Modify: `docker/Dockerfile:2` — `FROM php:8.5-cli AS base` → `FROM php:8.5.7-cli AS base`
- Modify: `docker/Dockerfile:18` — `FROM php:8.5-zts AS base-zts` → `FROM php:8.5.7-zts AS base-zts`

**Interfaces:**
- Consumes: Task 2's rebuilt images.
- Produces: PHP base reproducibly pinned to 8.5.7; later tasks (composer constraint normalization) depend on knowing the exact runtime version.

- [ ] **Step 1: Verify the current FROM lines**

```bash
grep -n "^FROM php:" docker/Dockerfile
```

Expected:
```
2:FROM php:8.5-cli AS base
18:FROM php:8.5-zts AS base-zts
```

- [ ] **Step 2: Edit Dockerfile line 2**

Change:
```dockerfile
FROM php:8.5-cli AS base
```
to:
```dockerfile
FROM php:8.5.7-cli AS base
```

- [ ] **Step 3: Edit Dockerfile line 18**

Change:
```dockerfile
FROM php:8.5-zts AS base-zts
```
to:
```dockerfile
FROM php:8.5.7-zts AS base-zts
```

- [ ] **Step 4: Rebuild images**

```bash
make down
make build
make up
```

Expected: image pull resolves `php:8.5.7-cli` and `php:8.5.7-zts` (both tags exist on Docker Hub — `php:8.5.7-cli` and `php:8.5.7-zts` are the official tags). Build succeeds.

- [ ] **Step 5: Verify PHP 8.5.7 is the active runtime**

```bash
docker compose exec php php --version | head -1
docker compose exec php-swoole php --version | head -1
docker compose exec php-fiber php --version | head -1
```

Expected each: `PHP 8.5.7 (cli) (built: …)` or similar with `8.5.7` exact.

- [ ] **Step 6: Run unit tests to confirm no regression from the patch bump**

```bash
make test-unit
```

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add docker/Dockerfile
git commit -m "chore(platform): pin PHP base image to 8.5.7

Sub-spec 0 deliverable item 2. Dockerfile base + base-zts stages now pin
to PHP 8.5.7 (latest patch) instead of the floating 8.5 tag, for CI
reproducibility.

Verification: php --version reports '8.5.7' in php / php-swoole / php-fiber
containers; make test-unit passes."
```

---

## Task 4: Add `ext-swoole` constraint to Swoole-dependent packages

**Files** (post-merge state — 4 packages):
- Modify: `packages/nexus-runtime-swoole/composer.json`
- Modify: `packages/nexus-worker-pool-swoole/composer.json`
- Modify: `packages/nexus-http-server-swoole/composer.json`
- Modify: `packages/nexus-http-server-swoole-threads/composer.json`

(If running on pre-merge state with only 16 packages, the latter two don't exist yet — only edit the 2 that do.)

**Interfaces:**
- Consumes: bumped Docker baseline from Task 3.
- Produces: a `>=6.2.1` floor on `ext-swoole` for every package that uses it; consumers installing these packages on an old Swoole get a `composer install` error immediately.

- [ ] **Step 1: List the actual files to edit**

```bash
grep -l '"ext-swoole":' packages/*/composer.json
```

Expected: 4 paths (post-merge) or 2 paths (pre-merge). Record the exact list — that's the file set this task touches.

- [ ] **Step 2: Inspect current ext-swoole constraint in one file**

```bash
grep -A1 '"ext-swoole":' packages/nexus-runtime-swoole/composer.json
```

Expected: `"ext-swoole": "*",`

- [ ] **Step 3: Edit each composer.json identified in Step 1**

For each file in the list, change:
```json
"ext-swoole": "*"
```
to:
```json
"ext-swoole": ">=6.2.1"
```

Preserve surrounding JSON formatting (PER-CS2.0 JSON style: 4-space indent; trailing commas where needed by the existing structure).

- [ ] **Step 4: Validate each modified composer.json**

```bash
for f in $(grep -l '"ext-swoole":' packages/*/composer.json); do
  echo "=== $f ==="
  docker compose exec -T php composer validate --strict "$f"
done
```

Expected per file: `./composer.json is valid`.

- [ ] **Step 5: Run a dry composer install at root to confirm resolution**

```bash
docker compose exec -T php composer install --dry-run 2>&1 | tail -20
```

Expected: no "version conflict" errors; resolution completes. (If `ext-swoole` in the running container is `6.2.1` per Task 2, resolution passes; if for any reason the bumped image isn't current, it fails — re-run `make build` and try again.)

- [ ] **Step 6: Run Swoole + worker-pool test suites**

```bash
make test-swoole
make test-cluster
```

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add packages/*/composer.json
git commit -m "chore(platform): require ext-swoole >=6.2.1 on Swoole packages

Sub-spec 0 deliverable item 3. Adds explicit ext-swoole version floor on
the packages that use Swoole runtime/extension functionality:

- nexus-runtime-swoole
- nexus-worker-pool-swoole
- nexus-http-server-swoole          (post HTTP/Doctrine merge)
- nexus-http-server-swoole-threads  (post HTTP/Doctrine merge)

Consumers installing these packages on Swoole <6.2.1 now get a clear
composer error instead of opaque runtime failures.

Verification: composer validate --strict passes per package; composer
install --dry-run resolves cleanly on the bumped baseline; make test-swoole
and make test-cluster pass."
```

---

## Task 5: Normalize `php` composer constraint to `>=8.5.7` across all packages

**Files:**
- Modify: `composer.json` (root)
- Modify: every `packages/*/composer.json` — 23 files (post-merge) or 15 files (pre-merge)
- Total: 24 files (post-merge) or 16 files (pre-merge)

**Interfaces:**
- Consumes: bumped runtime from Tasks 2–3.
- Produces: consistent `php` constraint across the monorepo; consumers can no longer satisfy the constraint on PHP < 8.5.7.

**Note on current state:** `grep '"php":' packages/*/composer.json` shows a mix of `">=8.5"` and (possibly) `"^8.5"`. This task normalizes ALL of them to `">=8.5.7"`. If any package currently has a different form (e.g. `"^8.5.0"`), still change to `">=8.5.7"`.

- [ ] **Step 1: List current constraints to confirm the delta**

```bash
echo "=== Root ==="; grep '"php":' composer.json
echo "=== Packages ==="; grep -H '"php":' packages/*/composer.json
```

Expected: each `php` line ends in `": ">=8.5",` or similar variant. Record any that are NOT `">=8.5"` so you can spot-check those specifically.

- [ ] **Step 2: Edit root composer.json**

Change the `"php"` constraint in `composer.json` from `">=8.5"` (or whatever the current value is) to `">=8.5.7"`.

- [ ] **Step 3: Edit every per-package composer.json**

For each file in `packages/*/composer.json`: change the `"php"` constraint to `">=8.5.7"`.

- [ ] **Step 4: Verify all files now match**

```bash
echo "=== Root ==="; grep '"php":' composer.json
echo "=== Packages ==="; grep -H '"php":' packages/*/composer.json
```

Expected: every line ends in `": ">=8.5.7",`. Zero variation.

- [ ] **Step 5: Validate every composer.json**

```bash
echo "=== Root ==="
docker compose exec -T php composer validate --strict
for f in packages/*/composer.json; do
  echo "=== $f ==="
  docker compose exec -T php composer validate --strict --working-dir=$(dirname "$f")
done
```

Expected: every package reports `./composer.json is valid`.

- [ ] **Step 6: Run composer install to confirm root resolves**

```bash
docker compose exec -T php composer install --no-interaction 2>&1 | tail -10
```

Expected: completes without errors; lock file updates if anything moved.

- [ ] **Step 7: Run full test suite**

```bash
make test
```

Expected: all pass. (Diff against `/tmp/baseline-test-output.txt` from Task 1 if any unexpected output.)

- [ ] **Step 8: Run static analysis**

```bash
make psalm
make phpcs
make cs
```

Expected: all clean.

- [ ] **Step 9: Commit**

```bash
git add composer.json composer.lock packages/*/composer.json
git commit -m "chore(platform): normalize php constraint to >=8.5.7 monorepo-wide

Sub-spec 0 deliverable item 4. Standardizes the php composer constraint
across root + every package composer.json from a mix of '>=8.5' / '^8.5'
to a uniform '>=8.5.7'.

This matches the Dockerfile pin (Task 3) and removes the inconsistency
where some published packages would have installed on PHP 8.5.0-8.5.6
while others required 8.5.x exactly.

Verification: composer validate --strict passes for root + every package;
composer install resolves cleanly; make test + make psalm + make phpcs +
make cs all green."
```

---

## Task 6: Full bumped-baseline verification

**Files:** none modified in this task — verification only.

**Interfaces:**
- Consumes: all of Tasks 2–5.
- Produces: signed-off green baseline ready for CI / PR.

- [ ] **Step 1: Run the complete test suite (covers every runtime)**

```bash
make test                # unit (all packages)
make test-fiber          # fiber integration
make test-swoole         # swoole integration
make test-cluster        # cluster (uses swoole)
make test-persistence    # persistence (in-memory + DBAL + Doctrine)
make test-serialization  # serialization
```

Expected: all suites pass. Note any new flakiness vs the Task 1 baseline.

- [ ] **Step 2: Run all static analysis**

```bash
make psalm    # Psalm level 1
make phpcs    # PHPCS / Slevomat
make cs       # PHP-CS-Fixer (PER-CS2.0)
```

Expected: clean across all three.

- [ ] **Step 3: Run mutation testing (slower; skip if time-pressed)**

```bash
make mutation
```

Expected: MSI ≥ 80%, covered ≥ 90% (project thresholds per CLAUDE.md).

- [ ] **Step 4: Verify the deptrac contract still passes**

```bash
docker compose exec -T php php -d error_reporting='E_ALL & ~E_DEPRECATED' vendor/bin/deptrac
```

Expected: zero violations.

- [ ] **Step 5: Spot-check that the platform versions are exactly what the spec mandates**

```bash
docker compose exec php php --version | head -1
docker compose exec php-swoole php -r 'echo SWOOLE_VERSION, PHP_EOL;'
grep '"php":' composer.json packages/*/composer.json | awk -F'"' '{print $4}' | sort -u
grep -A1 '"ext-swoole":' packages/*/composer.json | grep '">=' | head -5
```

Expected:
- PHP: `8.5.7` exactly
- Swoole: `6.2.1` exactly
- All `"php"` constraints: `">=8.5.7"` (single unique value)
- All `"ext-swoole"` constraints (for the 4 Swoole packages): `">=6.2.1"`

If any expectation fails, the corresponding earlier task wasn't completed correctly — go back and fix.

- [ ] **Step 6: No commit in this task — verification only.**

---

## Task 7: Push branch + verify CI matrix

**Files:** none — this is git + CI work.

**Interfaces:**
- Consumes: green local baseline from Task 6.
- Produces: a PR (or push to integration branch) with all CI jobs green; signals sub-spec 0 is shippable.

- [ ] **Step 1: Push the branch**

```bash
git push -u origin platform-bump-0
```

- [ ] **Step 2: Open a PR (if working via PR) — title and body shown below**

```bash
gh pr create \
  --title "chore(platform): bump to PHP 8.5.7 + Swoole 6.2.1 (sub-spec 0)" \
  --body "$(cat <<'EOF'
## Summary

Implements sub-spec 0 of the documentation revamp design (docs/superpowers/specs/2026-06-19-docs-revamp-design.md §13).

- Bump Docker Swoole build from `v6.2.0-rc1` to `v6.2.1` stable.
- Pin PHP base image to `8.5.7` (latest patch) on `base` + `base-zts` stages.
- Add `"ext-swoole": ">=6.2.1"` to the 4 Swoole-dependent packages.
- Normalize `"php": ">=8.5.7"` across root + every per-package composer.json.

No PHP source changes. Configuration only.

## Test plan

- [x] `make test` green locally on bumped baseline
- [x] `make psalm` / `make phpcs` / `make cs` green
- [x] `SWOOLE_VERSION == '6.2.1'` inside `php-swoole` container
- [x] `php --version` reports `8.5.7` in all three containers
- [x] `composer validate --strict` clean on root + every package
- [ ] CI matrix green (this PR's checks)
EOF
)"
```

- [ ] **Step 3: Watch CI to completion**

```bash
gh pr checks --watch
```

Expected: all jobs pass (build-images, lint, static-analysis, unit-tests, integration-fiber, integration-swoole, mutation-testing).

- [ ] **Step 4: Triage and fix any CI failure**

If a job fails:
1. Read the failing job's log: `gh run view --log-failed`.
2. Reproduce the failure locally inside the relevant Docker target.
3. Fix the root cause (do not retry blindly).
4. Commit the fix with `fix(platform): <reason>` prefix.
5. Push and re-watch CI.

- [ ] **Step 5: Hand off to user for merge**

Once CI is green:
- Notify the user with the PR URL.
- Do NOT merge to `main` autonomously — user owns sub-spec 0 per spec §13.
- After user merges, this plan is complete; downstream sub-specs (4a, 2, 5-Phase-1, 6) can begin.

---

## Done

When all 7 tasks are checked off and the PR is merged to the integration branch / `main`:

- Sub-spec 0 deliverables 1–6 (spec §13) are all met.
- The Stable claims for all 22 non-cluster packages in `/stability` (spec §12) become defensible — §2.5 criterion 4 ("integration tests against PHP 8.5 + Swoole 6.2.1 baseline") is satisfied.
- The downstream sub-specs can begin (their `Depends on 0` arrows in spec §10 are satisfied).
