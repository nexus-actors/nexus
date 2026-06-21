# IA Restructure + Content Moves + Subdomain Migration — Sub-spec 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the Docusaurus sidebar to match spec §5.1 (18 top-level sections, up from 12), rename `examples/`→`tutorials/`, group the flat 22-package list into 7 sub-categories, surface existing `docs/adr/*.md` as Docusaurus pages, switch `docusaurus.config.js` URL/baseUrl to `docs.nexusactors.com`, set up internal-path redirects via `@docusaurus/plugin-client-redirects`, and ship `bin/verify-doc-snippets` (the CI gate that subsequent sub-specs depend on).

**Architecture:** Pure structural change — no new prose content, no new code in Nexus packages. Touches `website/sidebars.js`, `website/docusaurus.config.js`, file moves under `website/docs/`, copies under `website/docs/architecture/adrs/` (sourced from `docs/adr/`), one new directory `website/docs/persistence/` (the new top-level section), three new sub-directories under `website/docs/operations/` and one under `website/docs/reference/`. Plus `bin/verify-doc-snippets` (PHP script + minimal autoload setup).

**Tech Stack:** Docusaurus 3.x (existing), `@docusaurus/plugin-client-redirects` (new dev dep), PHP 8.5+ for the verify script, Docker for execution.

## Global Constraints

Every task in this plan must honor these (from spec §2, §5, §7.3.1, and CLAUDE.md):

- **Docker for everything.** The `bin/verify-doc-snippets` script runs inside Nexus's `php` container. Never invoke `php` on the host.
- **URL stability** (spec rule #2). Every old `website/docs/<path>` URL must continue to resolve via `plugin-client-redirects`. The slug freeze (no rename in subsequent sub-spec 5 Phase 2) starts as soon as this plan lands.
- **No `Co-Authored-By: Claude` trailer.** Project rule.
- **New commits only, no `--amend`.** Fix forward if hooks fail.
- **Pre-approved hook+GPG bypass** for `docs/v1-launch` branch work (worktree+Docker grumphp + no pinentry): `--no-verify --no-gpg-sign` on every commit, documented in body.
- **Conventional commit prefixes:** `chore(docs):` for moves + sidebar restructure; `feat(docs):` for the verify-script; `chore(ci):` for plugin-client-redirects setup.
- **Content unchanged** — this plan moves files and rewrites the sidebar; it does NOT rewrite page bodies. Sub-spec 5 Phase 2 does that.
- **Atomic per-section move** — each file move + its redirect entry land in the SAME commit. Don't move 12 files then add 12 redirects separately; pair them.
- **Subdomain migration is NOT this plan's job** (spec §5.3 says Cloudflare `_redirects` lives in Astro landing project — sub-spec 1). This plan only does WITHIN-Docusaurus redirects. The docusaurus.config.js URL switch is here, but the actual DNS + Cloudflare project setup is sub-spec 1.

---

## File Structure

**Files modified:**

```
website/
├── sidebars.js                     # MAJOR REWRITE (157 → ~250 lines)
├── docusaurus.config.js            # url/baseUrl switch + plugin-client-redirects setup
├── package.json                    # add @docusaurus/plugin-client-redirects dev-dep
└── docs/
    ├── intro.md                    # RENAME → welcome.md (with redirect from /intro → /welcome)
    ├── examples/                   # RENAME → tutorials/ (with redirects from each)
    │   ├── overview.md             # → tutorials/overview.md
    │   └── wallet-app.md           # → tutorials/wallet-app.md
    ├── best-practices/             # 3 files MOVE → guides/ (with redirects)
    │   ├── message-design.md       # → guides/message-design.md
    │   ├── ask-vs-tell.md          # → guides/ask-vs-tell.md
    │   └── single-writer-aggregates.md # → guides/single-writer-aggregates.md
    ├── core-concepts/
    │   └── persistence.md          # MOVE → persistence/overview.md (with redirect)
    ├── http/                       # 3 files MOVE → operations/ (with redirects)
    │   ├── observability.md        # → operations/observability.md
    │   ├── production.md           # → operations/deployment.md (renamed too)
    │   └── performance.md          # → operations/performance-tuning.md (renamed; partial → sysctls.md)
    ├── architecture/
    │   └── performance.md          # MERGE into operations/performance-tuning.md (commit a no-op stub at old path with redirect)
    ├── runtimes/                   # 2 files MERGE → runtimes/standalone.md (with 2 redirects)
    │   ├── runtime-without-actors.md # → runtimes/standalone.md (redirect)
    │   └── runtime-standalone.md   # → runtimes/standalone.md (redirect)
    │
    │   # NEW directories — empty content placeholders so sidebar links don't 404 before sub-spec 3 fills them
    ├── guides/                     # NEW (Section 5 in spec sidebar)
    │   └── overview.md             # placeholder (1-paragraph "Guides section landing — content fills in sub-spec 3")
    ├── persistence/                # NEW top-level (Section 8)
    │   └── overview.md             # this is the moved core-concepts/persistence.md
    ├── operations/                 # NEW (Section 11)
    │   ├── overview.md             # NEW placeholder
    │   └── deployment/             # standalone subpages per spec
    │       ├── docker.md           # NEW placeholder
    │       ├── systemd.md          # NEW placeholder
    │       └── kubernetes.md       # NEW placeholder
    ├── reference/                  # ALREADY EXISTS (sub-spec 4b created it) — verify intact
    └── architecture/
        └── adrs/                   # NEW — surfaces existing /docs/adr/0001..0008
            ├── 0001-actor-model-architecture.md
            ├── 0002-immutable-behavior-api.md
            ├── 0003-dual-runtime-strategy.md
            ├── 0004-message-serialization.md
            ├── 0005-multi-process-clustering.md
            ├── 0006-persistence-event-sourcing.md
            ├── 0007-remote-ask-protocol.md
            └── 0008-worker-pool-cluster-separation.md

bin/
└── verify-doc-snippets             # NEW (PHP script)
```

**Files created as empty placeholders** (just frontmatter + 1-paragraph "this section will be populated in sub-spec 3"):

The 38 new pages listed in spec §7.2 are sub-spec 3's job. This plan creates ZERO new content files except where required to keep the sidebar from 404'ing — that's `guides/overview.md`, `operations/overview.md`, `operations/deployment/{docker,systemd,kubernetes}.md`, and any other top-of-section landing pages whose sidebar entries point at them. Total: ~6 placeholder pages.

**Files NOT modified:**
- The 84 existing page bodies — moved, renamed, but content unchanged.
- Anything outside `website/`.
- `composer.json` (Nexus root) — verify-script's autoload reads from existing `vendor/`.
- The `docs/adr/*.md` source files — sub-spec 2 COPIES them to `website/docs/architecture/adrs/` (rather than moving), so the ADR canonical location stays at `docs/adr/`. Optional: replace `docs/adr/*.md` with a 1-liner `[Moved to /website/docs/architecture/adrs/](...)` redirect; simpler to just keep both for V1.

---

## Pre-flight: confirm dependencies

```bash
# Plan-0 doesn't strictly block this plan (sub-spec 2 only touches website/ + bin/)
# but the worktree should be branched from feat/nexus-doctrine for the 84-page baseline.

cd /Users/tomas/Work/Monadial/CodeOSS/nexus
git branch --show-current  # should be feat/nexus-doctrine
find website/docs -name '*.md' | wc -l  # 84
ls docs/adr/ | wc -l  # 8 ADRs (spec said 0001-0005 but actually 0001-0008)
```

If on `main`, the page count is 43 — wrong baseline. Switch to `feat/nexus-doctrine` first.

---

## Task 1: Workspace + baseline verification

**Files:**
- No file edits.

**Interfaces:**
- Consumes: nothing (entry).
- Produces: worktree at `.claude/worktrees/ia-restructure-2`, Docusaurus deps installed, baseline `npm run build` green.

- [ ] **Step 1: Confirm starting point**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus
git branch --show-current   # feat/nexus-doctrine
ls website/docs/             # see existing sections
find website/docs -name '*.md' | wc -l   # 84
```

- [ ] **Step 2: Create the worktree**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus
git worktree add -b ia-restructure-2 .claude/worktrees/ia-restructure-2 feat/nexus-doctrine
```

- [ ] **Step 3: Install Docusaurus deps (if not already installed at worktree path)**

```bash
cd .claude/worktrees/ia-restructure-2/website
npm install --no-audit --no-fund 2>&1 | tail -5
```

- [ ] **Step 4: Baseline Docusaurus build**

```bash
cd .claude/worktrees/ia-restructure-2/website
npm run build 2>&1 | tail -10
```

Expected: build succeeds. May report a few pre-existing broken anchors (per Task 6 of plan-4b: 2 anchors in http/middleware + packages/http). Note them — those are pre-existing, not this plan's problem.

If the build FAILS on something other than the known pre-existing anchors, STOP and report — that's a baseline blocker.

- [ ] **Step 5: Record baseline**

```bash
cd .claude/worktrees/ia-restructure-2
npm run build --prefix website 2>&1 | tail -20 > /tmp/baseline-website-build.txt
echo "Baseline recorded $(date)"
```

No commit in this task.

---

## Task 2: Install `@docusaurus/plugin-client-redirects` + configure docusaurus.config.js

**Files:**
- Modify: `website/package.json` — add `@docusaurus/plugin-client-redirects` to devDependencies.
- Modify: `website/docusaurus.config.js` — register the plugin in `plugins: [...]`; switch `url` to `docs.nexusactors.com`.

**Interfaces:**
- Consumes: clean baseline from Task 1.
- Produces: plugin installed; subsequent tasks add `redirects: { ... }` config to the plugin options as they perform file moves.

- [ ] **Step 1: Install the plugin**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2/website
npm install --save-dev @docusaurus/plugin-client-redirects 2>&1 | tail -5
```

- [ ] **Step 2: Inspect current docusaurus.config.js**

```bash
cat docusaurus.config.js | head -40
```

Find the `url`, `baseUrl`, and `plugins` declarations.

- [ ] **Step 3: Edit docusaurus.config.js**

Change:
```js
url: 'https://nexusactors.com',
baseUrl: '/',
```
to:
```js
url: 'https://docs.nexusactors.com',
baseUrl: '/',
```

Add to the `plugins` array (create if absent):
```js
plugins: [
  [
    '@docusaurus/plugin-client-redirects',
    {
      redirects: [
        // Subsequent tasks append entries here as they move pages.
      ],
    },
  ],
],
```

- [ ] **Step 4: Verify build still passes**

```bash
npm run build 2>&1 | tail -5
```

Expected: success.

- [ ] **Step 5: Commit**

```bash
git add website/package.json website/package-lock.json website/docusaurus.config.js
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(ci): install @docusaurus/plugin-client-redirects; switch URL to docs.nexusactors.com

Sub-spec 2 setup. Installs the redirect plugin (subsequent tasks add
entries as pages move) and switches the site URL to docs.nexusactors.com
per spec §5.3.

Note: cross-subdomain redirect (nexusactors.com/docs/* → docs.nexusactors.com/*)
is sub-spec 1's responsibility — that lives in the Astro landing project's
Cloudflare _redirects file, not Docusaurus.

Hook + GPG signing bypassed (pre-approved): grumphp incompatible with
git-worktree+Docker; gpg-agent has no pinentry; change is docs-only.
EOF
)"
```

---

## Task 3: Surface existing ADRs as Docusaurus pages

**Files:**
- Create: `website/docs/architecture/adrs/0001..0008-*.md` (copy from `docs/adr/`)
- Create: `website/docs/architecture/adrs/_category_.json` (Docusaurus category metadata)

**Interfaces:**
- Consumes: `docs/adr/*.md` (existing, 8 files).
- Produces: 8 ADR pages addressable at `docs.nexusactors.com/architecture/adrs/<NNNN>-<slug>`.

- [ ] **Step 1: Create destination directory**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
mkdir -p website/docs/architecture/adrs
```

- [ ] **Step 2: Copy ADR files**

```bash
for adr in docs/adr/*.md; do
  cp "$adr" "website/docs/architecture/adrs/$(basename "$adr")"
done
ls website/docs/architecture/adrs/
```

Expected: 8 files copied.

- [ ] **Step 3: Add frontmatter to each**

Each ADR file needs Docusaurus frontmatter at the top (`---\ntitle: ...\nsidebar_position: N\n---`). Inspect each file — if frontmatter is absent, prepend:

```yaml
---
title: "ADR-0001: Actor Model Architecture"
sidebar_position: 1
---
```

Use the title from the file's first H1 if present; sidebar_position is the ADR number.

- [ ] **Step 4: Create `_category_.json`**

```json
{
  "label": "ADRs",
  "position": 3,
  "collapsible": true,
  "collapsed": true,
  "link": null
}
```

(`position: 3` because Architecture's other 2 pages are Design Philosophy + Internals.)

- [ ] **Step 5: Build + verify ADR pages render**

```bash
cd website && npm run build 2>&1 | tail -5
```

Expected: success. Verify by checking `website/build/architecture/adrs/0001-actor-model-architecture.html` exists.

- [ ] **Step 6: Commit**

```bash
git add website/docs/architecture/adrs/
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): surface 8 ADRs as Docusaurus pages under architecture/adrs/

Sub-spec 2 deliverable. Copies docs/adr/0001..0008 to
website/docs/architecture/adrs/ with Docusaurus frontmatter so they
render in the site sidebar.

Source files at docs/adr/ remain canonical; this is a copy, not a move.
Future ADR updates land in docs/adr/ first, then sync to website/docs/.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 4: Rename `examples/` → `tutorials/`

**Files:**
- Rename: `website/docs/examples/overview.md` → `website/docs/tutorials/overview.md`
- Rename: `website/docs/examples/wallet-app.md` → `website/docs/tutorials/wallet-app.md`
- Modify: `website/docusaurus.config.js` redirects array — add 2 entries.

**Interfaces:**
- Consumes: existing 2 examples files.
- Produces: same 2 files at `/tutorials/`, with 301 redirects from `/examples/*`.

- [ ] **Step 1: Move directory**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
git mv website/docs/examples website/docs/tutorials
ls website/docs/tutorials/
```

- [ ] **Step 2: Update any internal links**

```bash
grep -rln '/docs/examples/' website/docs/ | head
```

If any pages link to `/docs/examples/...`, update them to `/docs/tutorials/...`. (Redirect handles external links; internal links should use the new path for cleanliness.)

- [ ] **Step 3: Add redirects to plugin config**

Edit `website/docusaurus.config.js`. In the redirects array, add:

```js
{ from: '/examples', to: '/tutorials' },
{ from: '/examples/overview', to: '/tutorials/overview' },
{ from: '/examples/wallet-app', to: '/tutorials/wallet-app' },
```

- [ ] **Step 4: Build + verify**

```bash
cd website && npm run build 2>&1 | tail -5
# Verify the redirect pages emit
test -f build/examples/index.html && echo "OK: /examples redirect emitted"
test -f build/tutorials/overview/index.html && echo "OK: /tutorials/overview exists"
```

- [ ] **Step 5: Commit**

```bash
git add -A website/docs/tutorials website/docs/examples website/docusaurus.config.js
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): rename examples/ → tutorials/ (with redirects)

Sub-spec 2 §5.3. Renames the section to match spec §5.1 sidebar. Old
URLs (/examples/*) 301-redirect to new (/tutorials/*) via
@docusaurus/plugin-client-redirects.

Page bodies unchanged — sub-spec 5 Phase 2 handles voice/structure rewrite.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 5: Move 3 horizontal-pattern Best Practices pages → Guides

**Files:**
- Rename: `website/docs/best-practices/message-design.md` → `website/docs/guides/message-design.md`
- Rename: `website/docs/best-practices/ask-vs-tell.md` → `website/docs/guides/ask-vs-tell.md`
- Rename: `website/docs/best-practices/single-writer-aggregates.md` → `website/docs/guides/single-writer-aggregates.md`
- Create: `website/docs/guides/overview.md` (placeholder — sub-spec 3 will populate)
- Modify: `website/docusaurus.config.js` redirects array.

**Interfaces:**
- Consumes: existing 3 BP pages.
- Produces: same 3 pages under `/guides/`, with redirects.

- [ ] **Step 1: Move files**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
mkdir -p website/docs/guides
git mv website/docs/best-practices/message-design.md website/docs/guides/message-design.md
git mv website/docs/best-practices/ask-vs-tell.md website/docs/guides/ask-vs-tell.md
git mv website/docs/best-practices/single-writer-aggregates.md website/docs/guides/single-writer-aggregates.md
```

- [ ] **Step 2: Create `website/docs/guides/overview.md` placeholder**

```markdown
---
title: Guides
sidebar_position: 1
---

# Guides

Cookbook-style recipes for common Nexus patterns.

:::info Section under construction
This section's pages are being populated in the documentation revamp.
The 3 pages currently here moved from Best Practices.
:::

## Pages

- [Message Design](message-design)
- [Ask vs Tell](ask-vs-tell)
- [Single-Writer Aggregates](single-writer-aggregates)
- Routing Patterns (coming soon)
- Fan-out / Scatter-gather (coming soon)
- Rate Limiting (coming soon)
- Saga / Process Manager (coming soon)
- Standalone Integration (coming soon)
```

- [ ] **Step 3: Add redirects**

In `website/docusaurus.config.js` redirects array:

```js
{ from: '/best-practices/message-design', to: '/guides/message-design' },
{ from: '/best-practices/ask-vs-tell', to: '/guides/ask-vs-tell' },
{ from: '/best-practices/single-writer-aggregates', to: '/guides/single-writer-aggregates' },
```

- [ ] **Step 4: Build + verify**

```bash
cd website && npm run build 2>&1 | tail -5
```

- [ ] **Step 5: Commit**

```bash
git add -A website/docs/guides website/docs/best-practices website/docusaurus.config.js
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): move 3 horizontal-pattern Best Practices pages → Guides

Sub-spec 2 §5.1 + §11 res. 4. Moves message-design, ask-vs-tell, and
single-writer-aggregates from Best Practices to the new Guides section.
Best Practices retains the 8 prescriptive-opinion pages.

Old URLs (/best-practices/{message-design,ask-vs-tell,single-writer-aggregates})
301-redirect to /guides/*.

Page bodies unchanged.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 6: Promote `core-concepts/persistence.md` to top-level `/persistence/`

**Files:**
- Rename: `website/docs/core-concepts/persistence.md` → `website/docs/persistence/overview.md`
- Modify: `website/docusaurus.config.js` redirects.

**Interfaces:**
- Consumes: existing 1 page.
- Produces: file under new `/persistence/` top-level section, with redirect.

- [ ] **Step 1: Move**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
mkdir -p website/docs/persistence
git mv website/docs/core-concepts/persistence.md website/docs/persistence/overview.md
```

- [ ] **Step 2: Update internal links**

```bash
grep -rln '/core-concepts/persistence' website/docs/ | head
```

Update to `/persistence/overview` where found.

- [ ] **Step 3: Add redirect**

```js
{ from: '/core-concepts/persistence', to: '/persistence/overview' },
```

- [ ] **Step 4: Build + commit**

```bash
cd website && npm run build 2>&1 | tail -3 && cd ..
git add -A website/docs/persistence website/docs/core-concepts website/docusaurus.config.js
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): promote core-concepts/persistence → /persistence/overview

Sub-spec 2 §5.3. The persistence story is large enough for its own top-
level section per spec §5.1; this move starts that section. Sub-spec 3
fills in the rest (event-sourcing, durable-state, snapshots, etc.).

Old URL /core-concepts/persistence 301-redirects to /persistence/overview.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 7: Move 3 HTTP-prod pages + architecture/performance.md → Operations section

**Files:**
- Rename: `website/docs/http/observability.md` → `website/docs/operations/observability.md`
- Rename: `website/docs/http/production.md` → `website/docs/operations/deployment.md` (also rewritten heading)
- Rename: `website/docs/http/performance.md` → `website/docs/operations/performance-tuning.md`
- Move parts of `website/docs/http/performance.md` (the sysctls subsection) to a separate `website/docs/operations/sysctls.md` — OR if the page is short, leave sysctls inline and skip the split (decide based on file size).
- Delete (or stub): `website/docs/architecture/performance.md` → content merges into `operations/performance-tuning.md`.
- Create: `website/docs/operations/overview.md` placeholder.
- Create: `website/docs/operations/deployment/` subdir with placeholders `docker.md`, `systemd.md`, `kubernetes.md`.
- Modify: `website/docusaurus.config.js` redirects (~5 entries).

**Interfaces:**
- Consumes: 4 existing pages.
- Produces: Operations section seeded with the moved pages + placeholder structure.

- [ ] **Step 1: Inspect file sizes to decide splitting strategy**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
wc -l website/docs/http/{observability,production,performance}.md website/docs/architecture/performance.md
```

If `http/performance.md` is > 200 lines AND has a clear sysctls section, split it into 2 files. Otherwise leave intact as `operations/performance-tuning.md`.

- [ ] **Step 2: Create destination structure**

```bash
mkdir -p website/docs/operations/deployment
```

- [ ] **Step 3: Move files**

```bash
git mv website/docs/http/observability.md website/docs/operations/observability.md
git mv website/docs/http/production.md website/docs/operations/deployment.md
git mv website/docs/http/performance.md website/docs/operations/performance-tuning.md
```

- [ ] **Step 4: Merge architecture/performance.md content into operations/performance-tuning.md (or delete if duplicate)**

```bash
cat website/docs/architecture/performance.md   # inspect
```

If content overlaps performance-tuning, choose:
- **Append** the unique portions of architecture/performance.md to operations/performance-tuning.md, then `git rm` the architecture file.
- **OR** keep architecture/performance.md as-is and just add a top-of-page note "see also Operations > Performance Tuning".

Simpler for V1: `git rm website/docs/architecture/performance.md` + redirect old path to operations/performance-tuning.md. Sub-spec 5 Phase 2 can polish if needed.

```bash
git rm website/docs/architecture/performance.md
```

- [ ] **Step 5: Create placeholders**

`website/docs/operations/overview.md`:
```markdown
---
title: Operations
sidebar_position: 1
---

# Operations

Production runtime concerns: deployment, observability, performance tuning, and troubleshooting.

:::info Section under construction
This section's pages are being populated in the documentation revamp.
:::

## Pages

- [Deployment](deployment) — Docker, systemd, Kubernetes
- [Observability](observability)
- [Performance Tuning](performance-tuning)
- Metrics, Graceful Shutdown, Kernel Sysctls, Troubleshooting, Runbook, ZTS PHP Setup — coming soon
```

`website/docs/operations/deployment/docker.md`, `systemd.md`, `kubernetes.md` — each a minimal stub:

```markdown
---
title: Docker
sidebar_position: 1
---

# Docker

:::info Coming in sub-spec 3
This page is a placeholder. The full Docker deployment guide will be written in the next phase of the documentation revamp.
:::
```

(Same template per file with different title.)

NOTE: `operations/deployment.md` (parent) needs to also exist OR the sidebar handles parent-as-link via `_category_.json`. Decide based on Docusaurus convention — if the sidebar uses `category` with `link: { type: 'doc', id: 'operations/deployment' }`, then `deployment.md` exists as the section's landing. If using `link: { type: 'generated-index' }`, no parent file needed. The sidebar config in Task 8 decides; for now, keep `operations/deployment.md` as the moved-from-http-production page and add the 3 subpages under it.

- [ ] **Step 6: Add redirects (~5 entries)**

```js
{ from: '/http/observability', to: '/operations/observability' },
{ from: '/http/production', to: '/operations/deployment' },
{ from: '/http/performance', to: '/operations/performance-tuning' },
{ from: '/architecture/performance', to: '/operations/performance-tuning' },
```

- [ ] **Step 7: Build + verify**

```bash
cd website && npm run build 2>&1 | tail -5
```

If broken-link errors appear (because moved pages had inbound links from other pages), update those internal links. Don't suppress with `onBrokenLinks: 'warn'`.

- [ ] **Step 8: Commit**

```bash
git add -A website/docs/operations website/docs/http website/docs/architecture website/docusaurus.config.js
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): create Operations section; move 4 prod pages from HTTP/Architecture

Sub-spec 2 §5.3. Creates the new top-level Operations section per spec
§5.1. Moves:
- http/observability → operations/observability
- http/production → operations/deployment (parent page; 3 subpages added)
- http/performance → operations/performance-tuning
- architecture/performance → deleted (content was redundant; redirect to
  operations/performance-tuning)

Placeholders added for the section landing + 3 deployment subpages
(docker, systemd, kubernetes) so sidebar links don't 404 before sub-spec
3 writes the full content.

Old URLs redirect to new locations via plugin-client-redirects.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 8: Merge `runtime-without-actors.md` + `runtime-standalone.md` → `standalone.md`

**Files:**
- Merge: `website/docs/runtimes/runtime-without-actors.md` + `website/docs/runtimes/runtime-standalone.md` → `website/docs/runtimes/standalone.md`
- Modify: `website/docusaurus.config.js` redirects (2 entries).

**Interfaces:**
- Consumes: 2 near-duplicate pages.
- Produces: 1 merged page with 2 redirects.

- [ ] **Step 1: Inspect both files for overlap**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
wc -l website/docs/runtimes/runtime-{without-actors,standalone}.md
diff website/docs/runtimes/runtime-{without-actors,standalone}.md | head -40
```

- [ ] **Step 2: Create merged file**

The merge strategy:
- Use `runtime-standalone.md` as the base (it's the more canonical name).
- Append any UNIQUE content from `runtime-without-actors.md` (sections, examples) into the merged file at the right H2 boundaries.
- Rename to `standalone.md`.

```bash
cp website/docs/runtimes/runtime-standalone.md website/docs/runtimes/standalone.md
```

Then manually merge in the unique sections from `runtime-without-actors.md`. After merging, delete the originals:

```bash
git rm website/docs/runtimes/runtime-without-actors.md
git rm website/docs/runtimes/runtime-standalone.md
git add website/docs/runtimes/standalone.md
```

- [ ] **Step 3: Add 2 redirects**

```js
{ from: '/runtimes/runtime-without-actors', to: '/runtimes/standalone' },
{ from: '/runtimes/runtime-standalone', to: '/runtimes/standalone' },
```

- [ ] **Step 4: Build + commit**

```bash
cd website && npm run build 2>&1 | tail -3 && cd ..
git add -A website/docs/runtimes website/docusaurus.config.js
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): merge runtime-without-actors + runtime-standalone → standalone

Sub-spec 2 §5.3. The two pages were near-duplicates that confused readers
about which to start with. Merged into one canonical 'standalone' page
with both old URLs redirecting.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 9: Rename `intro.md` → `welcome.md`

**Files:**
- Rename: `website/docs/intro.md` → `website/docs/welcome.md`
- Modify: `website/docusaurus.config.js` redirects (1 entry).

**Interfaces:**
- Consumes: existing intro.
- Produces: same page renamed, with redirect.

- [ ] **Step 1: Move**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
git mv website/docs/intro.md website/docs/welcome.md
```

- [ ] **Step 2: Update internal links**

```bash
grep -rln '/docs/intro\|](intro' website/docs/ | head
```

Update each to `welcome`.

- [ ] **Step 3: Add redirect**

```js
{ from: '/intro', to: '/welcome' },
```

- [ ] **Step 4: Update sidebar references** — the existing `sidebars.js` references `'intro'` as the first item. Change to `'welcome'`. (Full sidebar rewrite happens in Task 11, but `npm run build` will fail until this one reference is fixed.)

- [ ] **Step 5: Build + commit**

```bash
cd website && npm run build 2>&1 | tail -3 && cd ..
git add -A website/docs/intro.md website/docs/welcome.md website/sidebars.js website/docusaurus.config.js
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): rename intro.md → welcome.md (with redirect)

Sub-spec 2 §5.3. Matches spec §5.1 sidebar entry 1 "Welcome".

/intro redirects to /welcome. Internal references updated.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 10: Slim placeholders for top-of-section landings

**Files:**
- Create: `website/docs/persistence/_category_.json` (or sidebar.js handles this — see Task 11)
- Create: any other missing parent pages for sections the new sidebar references.

**Interfaces:**
- Consumes: nothing.
- Produces: every sidebar entry from spec §5.1 resolves without 404 when the sidebar is rewritten in Task 11.

Most spec sections already have files. The genuinely-new sections that need placeholders (if not already created in Tasks 5/7):
- `guides/overview.md` — created in Task 5
- `persistence/overview.md` — created in Task 6 (it's the moved file)
- `operations/overview.md` — created in Task 7
- `operations/deployment/{docker,systemd,kubernetes}.md` — created in Task 7
- `reference/overview.md` — already exists from sub-spec 4b's `eb3e1d05` commit if merged; otherwise create placeholder

The remaining new sidebar entries (§5.1 items 16, 17: Upgrade & Changelog + FAQ & Glossary) are written by sub-spec 3, not this plan. **However**, this plan still needs placeholder landing pages so the sidebar doesn't 404. Create:

- [ ] **Step 1: Create remaining placeholders**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
mkdir -p website/docs/upgrade
mkdir -p website/docs/faq
```

`website/docs/upgrade/overview.md`:
```markdown
---
title: Upgrade & Changelog
sidebar_position: 1
---

# Upgrade & Changelog

:::info Coming in sub-spec 3
Upgrade guide + mirrored CHANGELOG will be written in the next phase.
:::
```

`website/docs/faq/overview.md`:
```markdown
---
title: FAQ & Glossary
sidebar_position: 1
---

# FAQ & Glossary

:::info Coming in sub-spec 3
FAQ entries + A-Z glossary will be written in the next phase.
:::
```

Plus check if `reference/overview.md` exists (if sub-spec 4b is merged into this branch, it should; if not, create placeholder).

- [ ] **Step 2: Build + commit**

```bash
cd website && npm run build 2>&1 | tail -3 && cd ..
git add website/docs/upgrade website/docs/faq website/docs/reference 2>/dev/null
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): create section-landing placeholders for new sidebar entries

Sub-spec 2. Adds minimal landing pages for new top-level sections that
sub-spec 3 will populate:
- guides/overview, persistence/overview, operations/overview (created
  in Tasks 5-7 alongside their moves)
- upgrade/overview, faq/overview (new in this task)
- operations/deployment/{docker,systemd,kubernetes} (created in Task 7)

Each placeholder has an :::info admonition pointing to sub-spec 3.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 11: Sidebar rewrite — wire all 18 spec §5.1 sections + grouped Packages

**Files:**
- MAJOR REWRITE: `website/sidebars.js`

**Interfaces:**
- Consumes: every file move/create from Tasks 3–10 + the existing 84 pages.
- Produces: a sidebar matching spec §5.1 exactly, including the 7-sub-category grouping for Packages.

This is the largest task in the plan. The current `sidebars.js` is 157 lines; the new one will be ~250.

- [ ] **Step 1: Inspect current sidebar shape**

```bash
cat website/sidebars.js | head -60
```

- [ ] **Step 2: Rewrite `website/sidebars.js`**

Replace the entire file with a structure matching spec §5.1. Outline:

```js
/** @type {import('@docusaurus/plugin-content-docs').SidebarsConfig} */
const sidebars = {
  docs: [
    'welcome',
    {
      type: 'category',
      label: 'Getting Started',
      collapsed: false,
      items: [
        'getting-started/installation',
        'getting-started/quick-start',
        'getting-started/concepts',
        'getting-started/persistent-actors',
      ],
    },
    {
      type: 'category',
      label: 'Tutorials',
      items: ['tutorials/overview', 'tutorials/wallet-app'],
    },
    {
      type: 'category',
      label: 'Core Concepts',
      items: [
        'core-concepts/actors',
        'core-concepts/behaviors',
        'core-concepts/props',
        'core-concepts/supervision',
        'core-concepts/mailboxes',
        'core-concepts/lifecycle',
        'core-concepts/ask-pattern',
        'core-concepts/passivation',
        'core-concepts/dead-letters',
        // 'core-concepts/nexus-thesis',  // confirm filename
      ],
    },
    {
      type: 'category',
      label: 'Guides',
      items: [
        'guides/overview',
        'guides/message-design',
        'guides/ask-vs-tell',
        'guides/single-writer-aggregates',
      ],
    },
    {
      type: 'category',
      label: 'Runtimes',
      items: [
        'runtimes/overview',
        'runtimes/bootstrap',
        'runtimes/standalone',
        'runtimes/fiber',
        'runtimes/swoole',
        'runtimes/step',
      ],
    },
    {
      type: 'category',
      label: 'HTTP',
      items: [
        'http/overview',
        'http/getting-started',
        'http/routing',
        'http/handlers',
        'http/auth',
        'http/middleware',
        'http/responses',
        'http/error-handling',
        'http/websockets',
        'http/actors-in-http',
        'http/servers',
      ],
    },
    {
      type: 'category',
      label: 'Persistence',
      items: ['persistence/overview'],
    },
    {
      type: 'category',
      label: 'Doctrine',
      items: [
        'doctrine/overview',
        'doctrine/connection-pool',
        'doctrine/entity-manager-pool',
        'doctrine/http-integration',
        'doctrine/entity-behavior',
      ],
    },
    {
      type: 'category',
      label: 'Scaling & Clustering',
      items: [
        'scaling/overview',
        'scaling/configuration',
        'scaling/bootstrap',
      ],
    },
    {
      type: 'category',
      label: 'Operations',
      items: [
        'operations/overview',
        {
          type: 'category',
          label: 'Deployment',
          items: [
            'operations/deployment',  // moved from http/production
            'operations/deployment/docker',
            'operations/deployment/systemd',
            'operations/deployment/kubernetes',
          ],
        },
        'operations/observability',
        'operations/performance-tuning',
      ],
    },
    {
      type: 'category',
      label: 'Best Practices',
      items: [
        'best-practices/overview',
        'best-practices/when-to-use-actors',
        'best-practices/supervision',
        'best-practices/observability',
        'best-practices/scaling',
        'best-practices/passivation',
        'best-practices/pooled-connections',
        'best-practices/testing',
      ],
    },
    // 'reference/*' — wired by sub-spec 4b if merged; otherwise add overview placeholder
    {
      type: 'category',
      label: 'Packages',
      collapsed: true,
      items: [
        {
          type: 'category',
          label: 'Foundation',
          items: [
            'packages/core',
            'packages/runtime',
          ],
        },
        {
          type: 'category',
          label: 'Runtimes',
          items: [
            'packages/runtime-fiber',
            'packages/runtime-swoole',
            'packages/runtime-step',
          ],
        },
        {
          type: 'category',
          label: 'HTTP',
          items: [
            'packages/http',
            'packages/http-ws',
            'packages/http-auth',
            // 'packages/http-toolkit',  // sub-spec 3 creates this
            'packages/http-server-swoole',
            'packages/http-server-swoole-threads',
          ],
        },
        {
          type: 'category',
          label: 'Persistence',
          items: [
            'packages/persistence',
            'packages/persistence-dbal',
            'packages/persistence-doctrine',
          ],
        },
        {
          type: 'category',
          label: 'Doctrine',
          items: [
            'packages/doctrine-dbal',
            'packages/doctrine-orm',
          ],
        },
        {
          type: 'category',
          label: 'Scaling',
          items: [
            'packages/cluster',
            'packages/worker-pool',
            'packages/worker-pool-swoole',
          ],
        },
        {
          type: 'category',
          label: 'Tooling',
          items: [
            'packages/app',
            'packages/logger',
            'packages/serialization',
            'packages/psalm',
          ],
        },
      ],
    },
    {
      type: 'category',
      label: 'Architecture',
      items: [
        'architecture/design-philosophy',
        'architecture/internals',
        {
          type: 'category',
          label: 'ADRs',
          collapsed: true,
          items: [
            'architecture/adrs/0001-actor-model-architecture',
            'architecture/adrs/0002-immutable-behavior-api',
            'architecture/adrs/0003-dual-runtime-strategy',
            'architecture/adrs/0004-message-serialization',
            'architecture/adrs/0005-multi-process-clustering',
            'architecture/adrs/0006-persistence-event-sourcing',
            'architecture/adrs/0007-remote-ask-protocol',
            'architecture/adrs/0008-worker-pool-cluster-separation',
          ],
        },
      ],
    },
    {
      type: 'category',
      label: 'Upgrade & Changelog',
      items: ['upgrade/overview'],
    },
    {
      type: 'category',
      label: 'FAQ & Glossary',
      items: ['faq/overview'],
    },
    {
      type: 'category',
      label: 'Contributing',
      items: [
        'contributing/development',
        'contributing/roadmap',
      ],
    },
  ],
};

module.exports = sidebars;
```

**IMPORTANT:** verify every doc-id (e.g. `'core-concepts/actors'`) corresponds to a real file (`website/docs/core-concepts/actors.md`). If a referenced file doesn't exist, Docusaurus build will fail. The sidebar above is a TEMPLATE — adjust file IDs to match what actually exists.

- [ ] **Step 3: Build until clean**

```bash
cd website && npm run build 2>&1 | tail -20
```

Expected failures + how to handle:
- "Cannot find file with id ..." → either the file doesn't exist (remove from sidebar) or filename mismatch (fix sidebar id).
- "Broken link" warnings on internal links — pre-existing per Task 1 baseline; ignore unless new.

Iterate until `[SUCCESS] Generated static files in "build".`.

- [ ] **Step 4: Commit**

```bash
git add website/sidebars.js
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
chore(docs): rewrite sidebars.js per spec §5.1 (18 sections, grouped Packages)

Sub-spec 2 main deliverable. Restructures the Docusaurus sidebar to match
spec §5.1:

- 18 top-level sections (was 12)
- New sections: Guides, Persistence (top-level), Operations, Reference
  (sub-spec 4b owns content), Upgrade & Changelog, FAQ & Glossary
- Packages grouped into 7 sub-categories: Foundation / Runtimes / HTTP /
  Persistence / Doctrine / Scaling / Tooling (was flat 22-item list)
- Best Practices retains 8 prescriptive pages (3 horizontal patterns
  moved to Guides per Task 5)
- ADRs surfaced under Architecture > ADRs (Task 3 copied 0001-0008)
- 'intro' → 'welcome' (Task 9)
- 'examples' → 'tutorials' (Task 4)

All page bodies unchanged. Sub-spec 3 fills the new placeholder sections.

Build passes; pre-existing broken anchors in http/middleware + packages/http
are not caused by this change.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 12: `bin/verify-doc-snippets` script

**Files:**
- Create: `bin/verify-doc-snippets` (PHP CLI script, executable)
- Create: `bin/verify-doc-snippets-test/*.md` (5 test fixture markdown files validating extraction + the 3 markers)
- Modify: `Makefile` — add `docs-verify` target running `bin/verify-doc-snippets`.

**Interfaces:**
- Consumes: every `.md` file under `website/docs/`.
- Produces: a CI-runnable script that extracts ```php blocks, lints them, runs psalm on them (where applicable). Exits 0 / 1 / 2 per spec §7.3.1 contract.

This script GATES sub-spec 3 (per spec §4 row 3 — content fill depends on this verify mechanism). Implementation can be relatively simple — a PHP script that:

1. Walks `website/docs/**/*.md`.
2. Extracts ` ```php` fenced blocks (regex).
3. Checks marker on fence line: `verify:skip`, `verify:lint-only`, or default (full verify).
4. For each block: concatenate with autoload header, write to tempfile, run `php -l`.
5. For non-`lint-only` blocks: also run `vendor/bin/psalm --no-cache <tempfile>`.
6. Aggregate results. Exit 0 if clean, 1 if any failures, 2 if script error.

- [ ] **Step 1: Write the script**

`bin/verify-doc-snippets`:

```php
#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * Sub-spec 2 deliverable. Per spec §7.3.1.
 *
 * Extracts ```php fenced code blocks from website/docs/**/*.md, lints
 * each via `php -l`, optionally runs Psalm on each, aggregates results.
 *
 * Markers (on the fence line):
 *   ```php                                  → full verify (php -l + psalm)
 *   ```php title="..." verify:skip          → skipped (reason required in HTML comment above)
 *   ```php title="..." verify:lint-only     → php -l only, no Psalm
 *
 * Exit codes:
 *   0 = all snippets clean
 *   1 = at least one snippet failed
 *   2 = script error (missing autoload, etc.)
 */

const DOCS_GLOB = __DIR__ . '/../website/docs';
const AUTOLOAD = __DIR__ . '/../vendor/autoload.php';

if (!file_exists(AUTOLOAD)) {
    fwrite(STDERR, "ERROR: vendor/autoload.php not found at " . AUTOLOAD . "\n");
    fwrite(STDERR, "Run `composer install` first.\n");
    exit(2);
}

$mdFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(DOCS_GLOB));
foreach ($iterator as $file) {
    if ($file->getExtension() === 'md' || $file->getExtension() === 'mdx') {
        $mdFiles[] = $file->getPathname();
    }
}

sort($mdFiles);
echo "Scanning " . count($mdFiles) . " markdown files...\n";

$totalSnippets = 0;
$skipped = 0;
$failed = 0;
$failures = [];

foreach ($mdFiles as $mdPath) {
    $content = file_get_contents($mdPath);
    // Extract ```php blocks (with optional metadata on the fence line)
    if (!preg_match_all('/```php([^\n]*)\n(.*?)```/s', $content, $matches, PREG_SET_ORDER)) {
        continue;
    }

    foreach ($matches as $i => $match) {
        $totalSnippets++;
        $fenceLine = $match[1];
        $code = $match[2];

        if (str_contains($fenceLine, 'verify:skip')) {
            $skipped++;
            continue;
        }

        $lintOnly = str_contains($fenceLine, 'verify:lint-only');

        // Write to tempfile with autoload header
        $tmpFile = tempnam(sys_get_temp_dir(), 'docsnip_') . '.php';
        $bootstrap = "<?php\nrequire_once '" . AUTOLOAD . "';\n";
        file_put_contents($tmpFile, $bootstrap . $code);

        // php -l
        $output = [];
        $exitCode = 0;
        exec("php -l " . escapeshellarg($tmpFile) . " 2>&1", $output, $exitCode);
        if ($exitCode !== 0) {
            $failed++;
            $failures[] = [
                'file' => $mdPath,
                'snippet' => $i + 1,
                'kind' => 'php -l',
                'output' => implode("\n", $output),
            ];
        }

        // psalm (skip if lint-only)
        if (!$lintOnly && $exitCode === 0) {
            exec("vendor/bin/psalm --no-cache --no-progress " . escapeshellarg($tmpFile) . " 2>&1", $psalmOutput, $psalmExit);
            if ($psalmExit !== 0) {
                $failed++;
                $failures[] = [
                    'file' => $mdPath,
                    'snippet' => $i + 1,
                    'kind' => 'psalm',
                    'output' => implode("\n", $psalmOutput),
                ];
            }
        }

        unlink($tmpFile);
    }
}

echo "Summary:\n";
echo "  Files scanned: " . count($mdFiles) . "\n";
echo "  Total snippets: $totalSnippets\n";
echo "  Skipped: $skipped\n";
echo "  Failed: $failed\n";

if ($failed > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $fail) {
        echo "\n--- {$fail['file']}, snippet #{$fail['snippet']} ({$fail['kind']}) ---\n";
        echo $fail['output'] . "\n";
    }
    exit(1);
}

exit(0);
```

- [ ] **Step 2: Make executable + create test fixtures**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
chmod +x bin/verify-doc-snippets
mkdir -p bin/verify-doc-snippets-test
```

Create `bin/verify-doc-snippets-test/01-clean.md`:
```markdown
# Clean snippet test

```php title="ok.php"
$x = 1;
$y = $x + 1;
```
```

Create `bin/verify-doc-snippets-test/02-syntax-error.md`:
```markdown
# Syntax error test

```php title="broken.php" verify:skip
<!-- Reason: intentional syntax error fixture -->
$x = 1 +;
```
```

(The `verify:skip` keeps this fixture from breaking CI.)

- [ ] **Step 3: Add `docs-verify` to Makefile**

```makefile
docs-verify: ## Verify ```php snippets in website/docs/ via bin/verify-doc-snippets
	@docker compose exec -T php bin/verify-doc-snippets
```

- [ ] **Step 4: Run the script against the actual docs**

```bash
docker compose exec -T php bin/verify-doc-snippets 2>&1 | tail -20
```

Expected output: scans 84+ files, reports total snippets + failures.

**Critical:** the docs are CURRENT (pre-revamp); they likely have many snippets that fail Psalm because old stale claims have wrong namespaces / wrong API signatures. Per spec, sub-spec 3 fixes those — for THIS task, the script just needs to RUN and report. It's allowed to find existing failures; those become sub-spec 3's work.

Document the baseline failure count in the commit message.

- [ ] **Step 5: Commit**

```bash
git add bin/verify-doc-snippets bin/verify-doc-snippets-test/ Makefile
git commit --no-verify --no-gpg-sign -m "$(cat <<'EOF'
feat(docs): bin/verify-doc-snippets — CI gate per spec §7.3.1

Sub-spec 2 deliverable. PHP CLI script that extracts ```php fenced
blocks from website/docs/**/*.md, lints each via `php -l`, runs Psalm
where applicable, aggregates results.

Markers per spec contract:
- ```php                        → full verify
- ```php verify:skip            → skipped (reason in HTML comment)
- ```php verify:lint-only       → php -l only

Exit codes: 0 clean / 1 failures / 2 script error.

Make target `make docs-verify` wraps the Docker invocation.

Test fixtures at bin/verify-doc-snippets-test/ exercise the 3 markers.

Baseline run against current 84 docs reports [N] failures — those are
stale claims sub-spec 3 will fix. The script itself is the CI gate;
its sub-spec 3 use will turn the count to 0.

Hook + GPG signing bypassed (pre-approved).
EOF
)"
```

---

## Task 13: Final integration check

**Files:** none — verification only.

**Interfaces:**
- Consumes: all of Tasks 2-12.
- Produces: a green Docusaurus build with the new sidebar + all redirects + the verify-script ready.

- [ ] **Step 1: Full Docusaurus build**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2/website
rm -rf build
npm run build 2>&1 | tail -10
```

Expected: SUCCESS. Pre-existing broken-anchor warnings count unchanged from baseline.

- [ ] **Step 2: Spot-check redirects work**

```bash
# Each old URL should have a redirect HTML page emitted
for url in /examples/wallet-app /best-practices/message-design /core-concepts/persistence /http/production /architecture/performance /intro; do
  path="build${url}/index.html"
  if [ -f "$path" ]; then echo "✓ $url has redirect"; else echo "✗ $url MISSING redirect"; fi
done
```

- [ ] **Step 3: Run verify-doc-snippets**

```bash
cd /Users/tomas/Work/Monadial/CodeOSS/nexus/.claude/worktrees/ia-restructure-2
docker compose exec -T php bin/verify-doc-snippets 2>&1 | tail -10
```

Note the failure count; sub-spec 3 owns reducing it to 0.

- [ ] **Step 4: Spot-check sidebar visually** (optional)

```bash
cd website && npm run start &  # serves on localhost:3000
# Browse, verify all 18 sections present, Packages grouped, Operations + Persistence top-level
# Stop with Ctrl+C
```

- [ ] **Step 5: Final commit (none — verification only)**

---

## Done

When all 13 tasks are checked off:

- Spec §5.1 sidebar live (18 sections, 7-sub-category Packages).
- Spec §5.3 file moves done (12 moves + 8 ADR copies).
- `docusaurus.config.js` switched to `docs.nexusactors.com`.
- `plugin-client-redirects` populated with ~15+ entries.
- `bin/verify-doc-snippets` available and runnable (`make docs-verify`).
- Spec §7.3.1 contract satisfied — sub-spec 3 can use the gate.
- Sub-specs 3, 4b, 5-Phase-2, 6 all unblocked (their `Depends on 2` arrow satisfied).

Cross-subdomain redirect (`nexusactors.com/docs/*` → `docs.nexusactors.com/*`) is sub-spec 1's job per spec §5.3 — not in this plan's scope.
