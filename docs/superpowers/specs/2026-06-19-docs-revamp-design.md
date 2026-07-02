# Nexus Documentation Revamp — Design

**Status:** Draft for review (post-second-pass revision)
**Author:** brainstorming with Claude (Opus 4.7)
**Date:** 2026-06-19
**Worktree:** `.claude/worktrees/docs-revamp` (branch `worktree-docs-revamp` from main)
**Trigger:** "If I merge HTTP and Doctrine I will start to promote this so I need totally revamp enterprise production ready great and awesome doc."

---

## 1. Purpose

Ship a launch-ready documentation revamp before public promotion of the HTTP + Doctrine merge. Quality bar: comparable to [doc.akka.io](https://doc.akka.io), [symfony.com/doc](https://symfony.com/doc), [doctrine-project.org](https://www.doctrine-project.org), and the DX bar of [docs.stripe.com](https://docs.stripe.com) / [vercel.com/docs](https://vercel.com/docs) / [nextjs.org/docs](https://nextjs.org/docs).

**Three audiences served:**

- **Developers** — can sit down and build a production app without open questions.
- **Managers** — can scan the production posture, stability matrix, and operational story without reading code.
- **CTOs / Evaluators** — can decide whether to adopt Nexus from `/why-nexus`, `/comparison`, `/stability`, and `/adoption` alone.

**Success criteria** are concrete in §14.

---

## 2. Hard rules

These constrain every sub-spec and every implementation plan.

1. **Docs map to code, with two narrow exceptions.** Every page must document either (a) code that exists in `packages/*/src` today, or (b) operational/configuration guidance for a real deployment scenario. The two exceptions are: (i) **"Preview" pages — limited to packages whose §12 status is `Experimental`**; (ii) `/comparison` cells naming a planned capability as "planned — see roadmap". A new "Preview" page requires a corresponding `Experimental` row in §12, added in the same PR. No other aspirational content. No empty placeholders.
2. **URL stability and page identity preserved; content may be rewritten.** Existing URLs continue to resolve (via redirects where pages move). Existing page *purposes* (Quick Start, Actors, Supervision …) stay. Within a page, voice/structure/diagrams/decision-trees/failure-modes content can be added or revised. The existing 11 Best Practices pages keep their URLs (8 retained in-section, 3 redirected to `Guides` per §11 res. 4).
3. **Pre-1.0 reality.** No versioned docs infrastructure — single living docs that reflects `main`. Add versioning when there are multiple supported versions in the wild.
4. **V1 integration scope is standalone-only.** Symfony and Laravel first-class integrations are post-V1.
5. **No `Co-Authored-By` trailers on commits.** Matches project CLAUDE.md.
6. **No social proof claims in V1.** No "Used by X" list, no case studies, no aspirational logos. `/adoption` ships with honest language.
7. **Failure modes are first-class content where applicable.** Core Concepts pages covering a **runtime mechanism with observable failure modes** MUST include a "Failure modes" subsection. Pages this applies to: `actors.md`, `behaviors.md`, `supervision.md`, `mailboxes.md`, `lifecycle.md`, `ask-pattern.md`, `dead-letters.md`, `passivation.md`. Pages this does NOT apply to (philosophical / pure-data): `nexus-thesis.md`, `props.md`, `envelopes.md`, `futures.md`.
8. **Decision trees are first-class content.** Every page presenting ≥2 equivalent APIs (`actors.md`, `persistence/overview.md`, `doctrine/overview.md`, `runtimes/overview.md`, `http/handlers.md`) MUST open with a 3–5 line "Choosing between …" decision block.
9. **Source markdown is UTF-8.** All `.md` files are UTF-8 encoded. EditorConfig + markdownlint enforce this. Code blocks ban smart quotes (em-dashes are fine in prose).

## 2.5 Stable definition (semver criteria)

A package is marked **Stable** on the `/stability` matrix only if all of these hold:

**Process criteria** (auditable from git/CI):
- Public API has not had a backward-incompatible change in the 4 weeks before the matrix lands. Clock starts at sub-spec 2 (IA) merge to `main`.
- 1.0-track semver commitment is in effect: minor releases must not break public types in `src/` (excluding `@internal`-tagged classes).
- Deprecations land with `@deprecated` PHPDoc + an entry in `CHANGELOG.md` *before* removal in a subsequent minor.
- The package has integration tests against the platform baseline (PHP 8.5 + Swoole 6.2.1 where Swoole is required).
- The Reference > Exceptions page (§7.7) documents every exception the package throws.

**API maturity criteria** (auditable from the docs site itself):
- The package has at least one **worked end-to-end example** in `tutorials/` or `guides/` or its own package page — not a stub.
- The package's public surface is **Psalm-level-1 clean from a consumer's perspective** (verified by an example app under `examples/`).
- The package's public methods have **no `@throws RuntimeException`** without a typed exception (untyped throws to be elevated or documented as `@throws \Throwable` with a recovery note in `reference/exceptions.md`).

A package that fails any criterion ships as **Experimental** (preview, expect breaks) until criteria are met. There is no Beta tier in V1.

---

## 3. Scope

### In scope (V1, launch-blocking)

- **A.** **NEW Astro landing site** at `nexusactors.com`: slim `/` + topic landings (`/http`, `/doctrine`, `/why-nexus`, `/comparison`, `/stability`, `/use-cases`, `/adoption`). Astro 5 (current), Tailwind, MDX for content pages, React/Vue islands for interactivity. Existing Docusaurus marketing components (Hero, Pillars, etc.) are not directly portable — landing components are rebuilt in Astro, sharing design tokens (logo SVGs, color palette, typography scales) with Docusaurus and phpDocumentor via a shared `nexus-brand/` package.
- **A2.** Site split — three subdomains, three tech stacks, one brand:
    - `nexusactors.com` — Astro landing + marketing
    - `docs.nexusactors.com` — Docusaurus narrative docs (moved from `nexusactors.com/docs/*`)
    - `api.nexusactors.com` — phpDocumentor API reference
- **B.** Information architecture restructure: new top-level Docusaurus sidebar (§5); section moves; grouped `Packages` list. Docusaurus `baseUrl` changes from `/` to `/` on the new subdomain.
- **C.** Content gap fill: stale-claim sweep; ~38 new pages mapping to existing code; per-section verification pass with executable code checks.
- **D.** Marketing/evaluator pages outside `/docs`: `/why-nexus`, `/comparison`, `/stability`, `/use-cases`, `/adoption`.
- **E.** Auto-generated API reference: **extend phpDocumentor v3** with a Nexus plugin + Twig template overrides for `@template` rendering; standalone site at `api.nexusactors.com/`; `@internal` tagging on ~25–30 engine internals; PHPDoc backfill on the top-25 front-door classes.
- **F.** Docusaurus copy buttons (enable + snapshot-test one block); GitHub Discussions enabled as the help surface; **no analytics in V1**.
- **G.** Full copywriting pass: Nexus docs style guide; voice/tone/structure rewrite of all 84 existing pages (post-feat/nexus-doctrine merge state, including HTTP+Doctrine+Best Practices sections); marketing and new pages crafted to the same bar. Includes failure-modes mandate (§2 rule #7) and decision-tree mandate (#8).
- **H.** Theme & Site Chrome (sub-spec 6): local `⌘K` search, runtime applicability badge, `related:` frontmatter cards, OG-image generator, custom Dead-Letter 404 page, glossary auto-linking, code file-path titles, Fiber/Swoole synced tabs, sticky right-rail TOC, runtime selector at sidebar top, mobile/dark/print stylesheets, a11y + link-rot CI gates.

### Out of scope (deferred to post-V1)

Algolia/DocSearch (local search replaces it for V1); Edit-on-GitHub links; runnable sandboxes; versioned docs; Symfony/Laravel first-class integration; case studies; multi-language docs; analytics + cookie consent; RSS feed.

---

## 4. Sub-spec decomposition

The revamp is decomposed into 8 implementation sub-specs (0, 1, 2, 3, 4a, 4b, 5, 6). Each gets its own implementation plan after this design is approved.

| # | Sub-spec | Deliverable | Code? | Depends on |
|---|---|---|---|---|
| **0** | **Platform bump** | `docker/Dockerfile`: `v6.2.0-rc1 → v6.2.1` across Swoole-build stage; `composer.json` (root + 24 packages): add `"ext-swoole": ">=6.2.1"` constraint where currently `"*"` (PHP 8.5 floor is already in place); CI matrix bumped; full test suite green on bumped platform. | **Yes** — Dockerfile + composer | nothing |
| 1 | **Astro landing site** (`nexusactors.com`) | NEW Astro 5 project at `landing/` in repo root (sibling to `website/`); `astro.config.mjs`; Tailwind setup; design tokens imported from `nexus-brand/` package (sub-spec 6); 8 routes (`/`, `/http`, `/doctrine`, `/why-nexus`, `/comparison`, `/stability`, `/use-cases`, `/adoption`); interactive islands (decision-tree cards, comparison matrix with sortable rows, stability matrix); marketing copy crafted per style guide; OG images generated at Astro build; sitemap; deployment workflow. | No (separate Astro project; design tokens only) | 0, 5-Phase-1, 6 |
| 2 | IA restructure + content moves + subdomain migration | `website/sidebars.js` rewrite; file moves (§5.3); rename `examples/`→`tutorials/`; group `packages/` into sub-categories; surface `docs/adr/*.md` as Docusaurus pages; **`docusaurus.config.js` `url`/`baseUrl` switch to `docs.nexusactors.com`**; **redirects layer** for every old `nexusactors.com/docs/*` URL → new `docs.nexusactors.com/*` URL (server-side 301 from the Astro landing host so old links still work) AND for every moved internal path (via `plugin-client-redirects`); `bin/verify-doc-snippets` script (§7.3.1) ships in this sub-spec. | No | 0 |
| 3 | Content fill | Stale-claim fixes (§7.1); 38 new pages (§7.2); per-section verification pass (§7.3); failure-modes subsections per §7.4 + §2 rule #7; decision-tree blocks (§7.5); ~17 mandatory Mermaid diagrams across 11 pages (§7.6); exception catalog (§7.7); upgrade log (§7.8); troubleshooting scenarios (§7.9); gotchas index (§7.10). | No | 0, 2, 5-Phase-1 |
| **4a** | **phpDocumentor extension** | Nexus phpDocumentor plugin (`nexus-actors/phpdoc-templates-plugin` — composer package) reading `@template`, `@template-extends`, `@template-implements`, `@template-covariant` tags and exposing them as first-class descriptor properties; custom Twig template overrides for `class.html.twig` and `method.html.twig` rendering template parameters in signatures/returns/class-headers; smoke test produces correctly-rendered pages for `ActorRef`, `Behavior`, `Props`, `BehaviorWithState`, `Future`; **emits `api-classes.json`** — flat catalog of every public class FQCN + its `api.nexusactors.com/...html` URL (consumed by sub-spec 6's class-name remark plugin, §8.5). | No (separate plugin repo) | 0 |
| 4b | API reference autogen | `@internal` tags on ~25–30 engine internals; PHPDoc backfill on top-25 front-door classes (§8.3); `phpdoc.dist.xml`; `.github/workflows/api-deploy.yml`; **Cloudflare Pages project `nexus-actors-api`** (§8.4); hand-curated top-25 front-door pages in Docusaurus `Reference/`; theme overlay using design tokens from sub-spec 6. | **Yes** — `@internal` PHPDoc tags + docblock additions across `packages/*/src` | 0, 2, 4a, 6 (for tokens.css) |
| 5 | Copywriting + style guide | **Phase 1**: style guide (voice, tone, terminology, Diataxis page templates, code-example conventions including mandatory file-path titles, Mermaid policy, failure-modes mandate, decision-tree mandate, UTF-8 + smart-quote ban). **Phase 2**: rewrite all 84 existing pages for voice/tone/structure/flow per §7.4 DoD checklist. | No | Phase 1: nothing. Phase 2: 0, 2, 5-Phase-1 (parallel with sub-spec 3 via disjoint file sets — see §10 + resolution 15) |
| 6 | Theme, Site Chrome & shared brand | **`nexus-brand/` package** (logo SVGs, color tokens, typography scales, spacing scale) consumed by all 3 sites (Astro/Docusaurus/phpDocumentor); Docusaurus chrome: local `⌘K` search plugin, `groupId="runtime"` Fiber/Swoole tabs, right-rail TOC, runtime applicability badge component, `related:` frontmatter + footer cards, OG-image generator at build (also used by sub-spec 1 Astro), custom "Dead Letter" 404 page, glossary auto-link remark plugin, **class-name auto-link remark plugin** (links `` `ClassName` `` to `api.nexusactors.com` — fed by `api-classes.json` from 4a, §8.5), favicon set, dark-mode-safe Prism theme, mobile breakpoints, print stylesheet for `/stability` and `/reference/*`; **cross-site CI gates**: `axe-core` on all 3 sites, `lychee` link-checker covering all 3 domains, **multi-host sitemap strategy** (§9.2). | No (theme/plugin + brand package) | 2, 4a (for `api-classes.json`) |

**Sequencing rationale (per user directives):**
- **Sub-spec 0** ships FIRST and lands on `main` standalone. Verifies CI on Swoole 6.2.1 / PHP 8.5.7 before docs claim it.
- **Sub-spec 4a (phpDocumentor extension)** ships SECOND. User directive: sub-spec 0 first, then phpDocumentor update. 4a is on `docs/v1-launch` branch.
- **Sub-specs 2, 5-Phase-1, 6** can start in parallel with 4a (or sequentially after) — all four are independent of each other.
- **Sub-specs 1, 3, 4b** start after their dependencies (1 needs 2+5-P1+6's OG generator; 3 needs 2+5-P1; 4b needs 4a + 6's tokens).
- **Sub-spec 5 Phase 2 runs in PARALLEL with sub-spec 3 — no merge conflicts because they touch disjoint file sets**:
    - Sub-spec 3 writes the **38 NEW pages** in the target voice (style guide already published).
    - Sub-spec 5 Phase 2 rewrites the **84 EXISTING pages** to the same voice.
    - Two scribes, two file sets, no overlap. Style guide enforces voice continuity across both sides.
    - A final audit pass (style guide author + sub-spec 5 owner) at the end of Phase 2 catches any drift; this is a quick read-through, not a third rewrite.

---

## 5. Information architecture (sub-spec 2 detail)

### 5.1 Final proposed sidebar

```
1.  Welcome                       ← renamed from "intro"
2.  Getting Started
       ├─ Installation            ← + smoke-test snippet at end (§7.2)
       ├─ Module Selector         ← NEW (decision tree: "I want X → install Y, Z")
       ├─ Quick Start             ← REWRITTEN (typed ask, no probe pattern)
       ├─ Local Dev Without Docker ← NEW
       ├─ First Persistent Actor
       ├─ Key Concepts
       └─ Common Mistakes         ← NEW (Top 10 day-one pitfalls)
3.  Tutorials                     ← renamed from "Examples"
       ├─ Overview
       └─ Wallet App
4.  Core Concepts
       ├─ Nexus Thesis            (no failure-modes per rule #7)
       ├─ Actors                  ← + failure modes + decision tree
       ├─ Behaviors               ← + failure modes
       ├─ Props                   (config object, no failure-modes per rule #7)
       ├─ Supervision             ← + failure modes
       ├─ Mailboxes               ← + failure modes
       ├─ Lifecycle               ← + failure modes
       ├─ Ask Pattern             ← + failure modes
       ├─ Envelopes               ← NEW (data carrier, no failure-modes)
       ├─ Dead Letters            ← NEW + failure modes
       ├─ Futures & FutureSlot    ← NEW (no failure-modes)
       └─ Passivation             ← + failure modes
5.  Guides
       ├─ Overview
       ├─ Message Design          ← moved from Best Practices
       ├─ Ask vs Tell             ← moved from Best Practices
       ├─ Single-Writer Aggregates ← moved from Best Practices
       ├─ Routing Patterns        ← NEW
       ├─ Fan-out / Scatter-gather ← NEW
       ├─ Rate Limiting (actor-side) ← NEW
       ├─ Saga / Process Manager  ← NEW
       └─ Standalone Integration  ← NEW
6.  Runtimes
       ├─ Overview                ← + decision tree
       ├─ Bootstrap
       ├─ Standalone              ← merge runtime-standalone + runtime-without-actors
       ├─ Fiber
       ├─ Swoole
       ├─ Step
       └─ Xdebug Setup            ← NEW
7.  HTTP
       ├─ Overview                ← + request lifecycle diagram
       ├─ Getting Started
       ├─ Routing
       ├─ Handlers                ← + decision tree (param resolver attributes)
       ├─ Auth                    (Bearer + JWT only)
       ├─ Middleware
       ├─ Responses
       ├─ Error Handling
       ├─ WebSockets
       ├─ Actors in HTTP          ← + actor-mode tree diagram
       ├─ Servers
       └─ HTTP Toolkit            ← NEW
8.  Persistence                   ← NEW top-level
       ├─ Overview                ← + decision tree
       ├─ Event Sourcing          ← + failure modes
       ├─ Durable State
       ├─ Snapshots & Retention
       ├─ Single-Writer & Replay Filter
       ├─ Stores
       └─ Testing
9.  Doctrine
       ├─ Overview                ← + decision tree
       ├─ Connection Pool
       ├─ Entity Manager Pool
       ├─ HTTP Integration
       ├─ Entity Behavior         ← + sequence diagram
       ├─ Migrations Workflow     ← NEW
       ├─ Second-Level Cache      ← NEW
       └─ Fixtures for Entity Behavior ← NEW
10. Scaling & Clustering
       ├─ Overview                ← + scaling-topology diagram
       ├─ Configuration
       ├─ Bootstrap
       ├─ Choosing Thread Count   ← NEW
       ├─ Cross-Worker Ask        ← NEW
       └─ Cluster Preview         (Experimental — see §12; allowed under rule #1 exception (i))
11. Operations                     ← NEW
       ├─ Overview
       ├─ Deployment
       │    ├─ Docker
       │    ├─ Systemd
       │    └─ Kubernetes         ← standalone page
       ├─ Observability
       ├─ Metrics                 ← NEW
       ├─ Graceful Shutdown
       ├─ Performance Tuning
       ├─ Kernel Sysctls
       ├─ Troubleshooting         ← 15 scenarios (§7.9)
       ├─ Runbook                 ← NEW (10 entries)
       └─ ZTS PHP Setup           ← NEW (acknowledges packaging reality; see §12)
12. Best Practices                 ← 8 pages retained (3 moved to Guides per §11 res. 4)
13. Reference                     ← NEW
       ├─ Overview
       ├─ Configuration
       ├─ System Messages
       ├─ Lifecycle Signals
       ├─ Exception Hierarchy     ← 41 entries (§7.7)
       ├─ Attribute Index
       ├─ Psalm Plugin Rules
       └─ Gotchas                 ← 21 entries (§7.10)
14. Packages                       ← grouped (Foundation / Runtimes / HTTP / Persistence / Doctrine / Scaling / Tooling)
15. Architecture
       ├─ Design Philosophy
       ├─ Internals
       └─ ADRs                    ← existing docs/adr/0001..0005
16. Upgrade & Changelog            ← NEW
       ├─ Upgrade Guide           (12 entries; §7.8)
       └─ Changelog               (CHANGELOG.md mirrored)
17. FAQ & Glossary                 ← NEW
       ├─ FAQ
       └─ Glossary                (auto-linked from sub-spec 6 remark plugin)
18. Contributing
       ├─ Development
       ├─ Your First PR           ← NEW (5-step happy path)
       ├─ Roadmap
       ├─ Release Process         ← NEW
       ├─ Splitsh-lite Workflow   ← NEW
       ├─ Security Disclosure     ← NEW
       ├─ Adding a Runtime        ← NEW
       ├─ Adding a Param Resolver ← NEW
       └─ Adding a Package        ← NEW (lists 24 composer.json + deptrac.yaml)
```

### 5.2 Astro landing site (sub-spec 1) — `nexusactors.com`

8 routes, all on the Astro site at the root domain:

- `/` — slim landing (§6).
- `/why-nexus` — evaluator-targeted thesis.
- `/comparison` — Nexus vs Amphp / Spiral / raw Swoole / queue+cron. Each row gets a visual marker (letter pill or Heroicon — not third-party logos) for scanability. Cells naming planned features use "planned — see roadmap" per rule #1. Sortable rows via an Astro interactive island.
- `/stability` — per-package matrix (§12). Criteria from §2.5 shown above the table. ZTS-dependent packages annotated with link to `docs.nexusactors.com/operations/zts-php-setup`.
- `/adoption` — License & IP / Maintainers & Governance / Support tiers / Adoption curve / Project continuity (replaces "Exit strategy" per second-pass finding 21).
- `/use-cases` — expanded UseCases section.
- `/http` — topic landing.
- `/doctrine` — topic landing.

**Cross-site links:** Every CTA from the Astro landing into docs uses absolute URLs to `docs.nexusactors.com/...`; every link into the API ref uses `api.nexusactors.com/...`. Astro has no Docusaurus dependency.

**Canonical URL strategy** (across 3 subdomains, closes second-pass finding 17): `nexusactors.com/http` (marketing) and `docs.nexusactors.com/http/overview` (developer reference) are distinct pages — marketing prose vs developer prose. Each is self-canonical (`<link rel="canonical">` points to itself). Style guide specifies the distinct framing rule and bans copy-paste between the two pages.

### 5.3 Section moves, renames, AND subdomain migration

**Subdomain migration (NEW — must happen with site split):**
- Old URL `https://nexusactors.com/docs/<path>` MUST 301 to `https://docs.nexusactors.com/<path>`.
- Implemented at the Astro host's redirect layer (Cloudflare Pages `_redirects` file, or `_redirects` for Netlify, or platform-equivalent). Astro site owns this layer because it owns the apex domain.
- All `<path>` segments preserved; only the host changes.
- `lychee` verifies these in CI.

**File-level moves inside Docusaurus** (same 12 moves as prior version). All moved pages get redirects from old URLs via `@docusaurus/plugin-client-redirects`. **Slug freeze:** sub-spec 5 Phase 2 MUST NOT rename slugs (closes second-pass finding 22).

---

## 6. Astro landing site (sub-spec 1 detail)

### 6.1 Slim `/` structure (6 sections)

1. **Hero** — headline + subheadline; ready-to-run code snippet (verified against sub-spec 0 platform); CTAs: Quick Start (`docs.nexusactors.com/getting-started/quick-start`) / Docs / GitHub / Adoption; install line; "Run this in Docker" inline tab (Astro island).
2. **One-line proof per pillar** (3 cards) — each links to a topic landing.
3. **The integrated story** (3 cards: Actors / HTTP+WS / Doctrine) — link to topic landings + docs.
4. **One rotating showcase code block** — EntityBehavior.
5. **"What do you want to do?"** decision-tree cards (Astro island with click-through animations).
6. **Bottom CTA** — Quick Start + Browse Packages + `/why-nexus`.

### 6.2 Topic landings

Same 7 pages as §5.2 above. Each is one Astro route with sections matched to the topic:
- `/http`: Hero+code → Routing/Handlers/Middleware → Actor integration → WebSockets → Production posture → CTA to docs.
- `/doctrine`: Hero+code → Pooled connections → EntityBehavior → Transactions → CTA to docs.
- `/why-nexus`: Production gap → Worker-farm rip-out → Type safety → Comparison teaser → CTAs.
- `/comparison`: Sortable matrix.
- `/stability`: Per-package matrix with criteria header.
- `/adoption`: 5 named sections.
- `/use-cases`: 6 expanded cards.

### 6.3 Astro project layout

```
landing/                                # NEW Astro project, sibling to website/
├── astro.config.mjs
├── package.json                        # astro, @astrojs/tailwind, @astrojs/mdx, @astrojs/react
├── tailwind.config.mjs                 # imports tokens from ../nexus-brand
├── public/                             # static assets, favicons (shared with brand)
└── src/
    ├── layouts/
    │   ├── Base.astro                  # head + nav + footer; brand-aware
    │   └── Marketing.astro             # extends Base; OG meta, sitemap
    ├── components/                     # Astro components (no JS) + React islands (interactive)
    │   ├── Hero.astro
    │   ├── PillarGrid.astro
    │   ├── ShowcaseSection.astro
    │   ├── CodeBlock.astro             # syntax-highlighted via shiki
    │   ├── DecisionTreeCards.tsx       # React island
    │   ├── ComparisonMatrix.tsx        # React island, sortable
    │   ├── StabilityMatrix.astro
    │   ├── CtaBlock.astro
    │   └── FooterRelated.astro
    ├── content/                        # MDX content collections
    │   ├── why-nexus.mdx
    │   ├── adoption.mdx
    │   └── use-cases.mdx
    └── pages/
        ├── index.astro                 # /
        ├── http.astro
        ├── doctrine.astro
        ├── why-nexus.astro
        ├── comparison.astro
        ├── stability.astro
        ├── adoption.astro
        ├── use-cases.astro
        └── 404.astro                   # Dead-Letter themed
```

**Why Astro:** static-first with islands architecture; built-in MDX; better Lighthouse scores than React-app shells; full design freedom (Tailwind + custom CSS, no Docusaurus theme override fight). The original Docusaurus landing components (Hero, Pillars, etc.) inform the Astro rebuild but are not directly portable — Astro components are simpler and faster.

**`nexus-brand/` package location and pinning:** Repo-local workspace package at `nexus-brand/` (sibling to `landing/`, `website/`, `packages/`). Path-imported by both `landing/` (Astro via TS path import + Tailwind config) and `website/` (Docusaurus via Webpack alias). NOT published to npm. All 3 sites version-lock automatically via the monorepo's single git SHA — no cross-site drift possible. phpDocumentor consumes `nexus-brand/tokens.css` directly via the theme overlay path.

OG images generated at Astro build time via a small build script in `landing/scripts/og-gen.mjs` using Satori or @vercel/og. Generation shared mechanism with Docusaurus's OG generator (sub-spec 6 deliverable in `nexus-brand/scripts/og-gen.mjs`, consumed by both sites).

---

## 7. Content fill plan (sub-spec 3 detail)

**Page count baseline (verified):** The launch baseline is `main + feat/nexus-doctrine` merged. Running `find website/docs -name '*.md' | wc -l` on `feat/nexus-doctrine` produces **84 existing pages**. (The `main` branch alone has 43; the additional 41 pages come from the HTTP/Doctrine/Best Practices work currently on `feat/nexus-doctrine` and slated to merge to `main` as part of the promotion path.) All effort estimates and DoD counts are based on 84.

Per-section breakdown (launch state): `packages/` 22, `http/` 14, `best-practices/` 11, `core-concepts/` 10, `runtimes/` 7, `doctrine/` 5, `getting-started/` 4, `scaling/` 3, `architecture/` 3, `examples/` 2, `contributing/` 2, `intro.md` 1.

### 7.1 Stale-claim sweep

Platform versions to set everywhere (per §13 / sub-spec 0):
- PHP: **8.5** (already in composer.json; just fix the docs that say 8.1)
- Swoole: **6.2.1+** (real bump: Dockerfile is at `v6.2.0-rc1`)
- ZTS PHP required for `nexus-worker-pool-swoole` and `nexus-http-server-swoole-threads`

| Page | Stale claim | Fix |
|---|---|---|
| `intro.md` L14, `runtimes/overview.md` L66, `runtimes/fiber.md` L8 / L88 | "PHP 8.1+ native Fibers" | "PHP 8.5+ (current floor)" |
| `getting-started/installation.md` L11, `runtimes/swoole.md` L8, `runtimes/overview.md` L67 | "Swoole 5.0+" everywhere | "Swoole 6.2.1+ (ZTS PHP required for worker-pool-swoole and http-server-swoole-threads)" |
| `doctrine/entity-behavior.md` L241 | closure-factory `ask()` | `ask(message, timeout)->await()` |
| `doctrine/entity-behavior.md` L190 | `use Monadial\Nexus\Core\Duration` | `use Monadial\Nexus\Runtime\Duration` |
| `getting-started/concepts.md` L41–45 | "Ask creates a temporary actor" | "Ask uses runtime `FutureSlot`" |
| `intro.md` Packages table | `nexus-http-toolkit` referenced, no `packages/http-toolkit.md` | Create the page; add sidebar entry |
| `examples/overview.md` | "Curated list" wording | Match actual count |

### 7.2 New pages (38 total, every page maps to existing code)

**Runtimes (1):** `runtimes/xdebug.md`.

**Getting Started (5):** `getting-started/module-selector.md`, `getting-started/local-dev-without-docker.md`, `getting-started/common-mistakes.md`, **`getting-started/installation.md` smoke-test addition** (closes coverage finding #44 — appends a 10-line `smoke.php` that spawns a counter actor and prints `OK`; failure means autoload/PHP-version/extension mismatch), **REWRITE `getting-started/quick-start.md`** to use typed `ask()->await()` with `Behavior<Increment|GetCount>` protocol, graceful shutdown via `Behavior::stopped()`.

**Guides (5):** `guides/overview.md`, `guides/routing-patterns.md`, `guides/fan-out.md`, `guides/rate-limiting-actor.md`, `guides/saga.md`, `guides/standalone-integration.md` (plus 3 moved from Best Practices).

**Core Concepts (3):** `core-concepts/envelopes.md`, `core-concepts/dead-letters.md`, `core-concepts/futures.md`.

**Persistence (7 new section):** `persistence/{overview,event-sourcing,durable-state,snapshots,single-writer,stores,testing}.md`.

**Doctrine (3):** `doctrine/{migrations,second-level-cache,fixtures}.md`.

**Scaling (2):** `scaling/{choosing-thread-count,cross-worker-ask}.md`.

**Operations (11 new section):** `operations/{overview,observability,metrics,graceful-shutdown,performance-tuning,sysctls,troubleshooting,runbook,zts-php-setup}.md`, `operations/deployment/{docker,systemd,kubernetes}.md`.

**Reference (7):** `reference/{overview,config,system-messages,lifecycle-signals,exceptions,attributes,psalm-rules,gotchas}.md`.

**Packages (2):** `packages/http-toolkit.md`, `packages/nexus.md` (meta-package page — 1 paragraph + install line + link-out to the 3 packages it pulls in: nexus-core, nexus-runtime-fiber, nexus-serialization).

**FAQ/Glossary/Upgrade (3):** `faq.md`, `glossary.md`, `upgrade.md`.

**Contributing (6):** `contributing/{your-first-pr,release-process,splitsh,security,adding-a-runtime,adding-a-param-resolver,adding-a-package}.md`.

**Architecture (5):** `architecture/adrs/0001..0005-*.md` (surface existing).

### 7.3 Per-section verification pass

Every existing page in `getting-started/`, `core-concepts/`, `http/`, `doctrine/`, `runtimes/`: every fenced ```php block extracted by `bin/verify-doc-snippets` and run through `php -l` + Psalm at CI time. Discrepancies fixed inline.

### 7.3.1 `bin/verify-doc-snippets` contract (closes second-pass finding 16)

The script is shipped in sub-spec 2 (because it gates CI for everyone else). Contract:

- **Input:** glob of `*.md` files under `website/docs/`.
- **Extracts** every ```php block; concatenates each block into a tempfile with a shared autoload header (`require __DIR__ . '/../vendor/autoload.php';`).
- **Markers:**
    - ` ```php` — verified.
    - ` ```php title="…" verify:skip` — skipped, with one-line reason required in HTML comment immediately above the block.
    - ` ```php title="…" verify:lint-only` — runs `php -l` only, skips Psalm (use for snippets showing a deliberately invalid pattern in a "don't do this" callout).
- **Composer autoload context:** uses the monorepo root's `vendor/autoload.php`. Snippets may use any class from the public surface of any package.
- **Expected runtime:** <30s for the full corpus.
- **Exit codes:** 0 = all clean; 1 = at least one snippet fails; 2 = script error (missing autoload, etc.). CI fails build on non-zero.
- **Special cases:** snippets needing DB setup get `verify:skip` with reason; snippets intentionally showing broken patterns get `verify:lint-only` or `verify:skip`.

### 7.4 Per-page DoD checklist (sub-spec 5 Phase 2)

- [ ] Opening sentence ≤2 sentences stating what + who-for.
- [ ] Closing section: cross-links + next read.
- [ ] `related:` frontmatter populated.
- [ ] Code blocks have `title="..."` file paths.
- [ ] Code blocks ≤30 lines (≤80 max with `<details>` for full file).
- [ ] If page is in §2 rule #7 list: has "Failure modes" subsection.
- [ ] If page covers ≥2 equivalent APIs (§7.5): has decision-tree block.
- [ ] If listed in §7.6: has Mermaid diagram(s).
- [ ] Passes `bin/verify-doc-snippets`.
- [ ] Reading time estimate (sub-spec 6 plugin).
- [ ] UTF-8 encoded; no smart quotes inside code blocks.
- [ ] Slug unchanged from sub-spec 2's IA (no rename).

### 7.5 Decision-tree blocks — required pages

`core-concepts/actors.md`, `persistence/overview.md`, `doctrine/overview.md`, `runtimes/overview.md`, `http/handlers.md`.

### 7.6 Diagrams required — 17 Mermaid diagrams across 9 docs pages + 1 SVG on landing

Each page gets the listed diagrams (counted individually, total 17 Mermaid):

- `core-concepts/actors.md` — message-flow sequence. **1**
- `core-concepts/supervision.md` — exception-propagation flowchart + restart-lifecycle sequence + OneForOne-vs-AllForOne state. **3**
- `core-concepts/lifecycle.md` — actor-state diagram + graceful-shutdown sequence. **2**
- `core-concepts/ask-pattern.md` — request-reply sequence. **1**
- `persistence/event-sourcing.md` (replaces `core-concepts/persistence.md` per IA move §5.3) — recovery sequence + command-path flowchart + writer-conflict sequence + replay sequence. **4**
- `scaling/overview.md` — topology flowchart + cross-worker tell/ask sequence. **2**
- `http/overview.md` — request-lifecycle diagram. **1**
- `http/actors-in-http.md` — actor-mode tree. **1**
- `doctrine/entity-behavior.md` — `EntityRefFactory` lifecycle + passivation sequence. **2**
- Landing `/` — architecture banner SVG (Actors+HTTP+WS+Doctrine stack). **1 SVG (not counted in Mermaid total)**

**Total: 17 Mermaid + 1 SVG = 18 diagrams across 10 distinct page targets.** Success criterion §14 #17 reflects this.

### 7.7 Exception catalog (`reference/exceptions.md`)

41 exceptions, 26 with full Cause/Resolution entries. Untyped throws (128 sites) acknowledged in a subsection.

### 7.8 Upgrade log (`upgrade.md`) — 12 entries

From long-tail audit: `ActorSystem::shutdown(Duration)` deadline-driven; `Mailbox::isClosed()` added; `SwooleMailbox::enqueue()` safe outside coroutine; auto-prune dead children; `ReceiveTimeout` signal; `EntityBehavior`/`EntityRefFactory`; `#[ReplyType]`; `ParamResolver` registry; `HttpApp::poolSingleton()` requires `PoolSingletonSpawner`; `BodySizeLimitMiddleware` constructor change; `AuthChallenge::__construct` collapse; new Psalm rules.

### 7.9 Troubleshooting scenarios (`operations/troubleshooting.md`) — 15 entries

From long-tail audit.

### 7.10 Gotchas index (`reference/gotchas.md`) — 21 entries

From long-tail audit.

---

## 8. API reference autogen (sub-specs 4a + 4b)

### 8.1 Extending phpDocumentor v3 for `@template` (sub-spec 4a)

**Path committed: EXTEND.** phpDocumentor v3 is the toolchain; we augment it to render `@template` correctly. No GO/NO-GO spike — the spec commits to building the extension. See phpDocumentor architecture docs: https://docs.phpdoc.org/

**All phpDocumentor work runs in Docker** (per project convention — see CLAUDE.md "Always use Docker for everything"). Both sub-spec 4a (plugin development) and sub-spec 4b (autogen runs) execute through `docker compose exec php phpdoc ...` against a versioned phpDocumentor install in the container. The plugin's own composer.json + tests run in the same container. No host PHP / host phpDocumentor.

**Why EXTEND beats alternatives:**
- phpDocumentor v3 already uses `phpdocumentor/type-resolver` which parses PHPStan/Psalm `@template` syntax — the data is in the reflection layer.
- The descriptor and Twig layers either drop the data or have no rendering blocks.
- A custom `nikic/php-parser` emitter (the "NO-GO" alternative) throws away phpDocumentor's mature class-tree / search / cross-linking / theme. EXTEND keeps all of that.

**Sub-spec 4a deliverables:**

1. **Nexus phpDocumentor plugin** (`nexus-actors/phpdoc-templates-plugin`, separate composer package).
    - Hooks the descriptor pipeline (`Descriptor\Builder\AssemblerInterface` / `ProjectDescriptorBuilder` events).
    - Reads `@template`, `@template-extends`, `@template-implements`, `@template-covariant` tags via existing `phpdocumentor/type-resolver`.
    - Exposes them as first-class properties on class/method descriptors (`getTemplateParameters(): array<TemplateDescriptor>`).
    - Estimated effort: ~1 week.
2. **Custom Twig template overrides** via `--template` flag.
    - Override `class.html.twig` — render `<T>`, `<T of Message>`, `<T, S>` after the class name in the page header and in the type tree.
    - Override `method.html.twig` — render template parameters bound by the method (e.g. `ask<R>(...)`) in the method synopsis and return type.
    - Override `signature.html.twig` (if present) — render `ActorRef<T>` correctly in cross-references.
    - Estimated effort: ~3 days.
3. **Smoke test** producing correctly-rendered HTML pages for these 5 classes; committed to plugin repo:
    - `Monadial\Nexus\Core\Actor\ActorRef<T>`
    - `Monadial\Nexus\Core\Actor\Behavior<T>`
    - `Monadial\Nexus\Core\Actor\Props<T>`
    - `Monadial\Nexus\Core\Actor\BehaviorWithState<T, S>`
    - `Monadial\Nexus\Runtime\Future<T>`

**Acceptance:** the 5 smoke-test pages show template parameters in the class header, in method signatures, and in the type tree — verifiable by HTML grep for the literal `<T>` strings.

**Total effort:** ~1.5–2 weeks (one developer).

### 8.2 Stack & scale

- **Generator:** phpDocumentor v3 + Nexus phpdoc-templates-plugin (sub-spec 4a).
- **Output:** static HTML at `api.nexusactors.com/`.
- **Scale:** 470 public types, 1,291 public methods → ~410–415 types after exclusions.

### 8.3 Pre-autogen prep

1. **Tag engine internals `@internal`** (list in long-tail audit).
2. **Exclude `nexus-psalm` hook classes** via `phpdoc.dist.xml`.
3. **PHPDoc backfill on top-25 front-door classes** (~50 hours of writing). **Other packages: hidden from the sidebar/index of `api.nexusactors.com` until their docblock summaries land** (closes second-pass finding 9 — no "coming soon" banner; non-curated packages don't appear at all in V1's API site index, but their auto-generated pages remain crawlable for deep-links).
4. **Hand-curated top-25 front-door pages** in Docusaurus `Reference/`.

### 8.4 Hosting decision (3-site topology, all on Cloudflare Pages)

Per user directive: **all 3 sites hosted on Cloudflare Pages**. One platform, one auth, one CI workflow shape, one analytics surface (if enabled later), one DNS zone.

| Subdomain | Site | Source | Cloudflare Pages project | DNS |
|---|---|---|---|---|
| `nexusactors.com` (apex) | Astro landing | `landing/` (sub-spec 1) | `nexus-actors-landing` | apex via CNAME flattening → `nexus-actors-landing.pages.dev` |
| `docs.nexusactors.com` | Docusaurus | `website/` (sub-spec 2) | `nexus-actors-docs` | CNAME → `nexus-actors-docs.pages.dev` |
| `api.nexusactors.com` | phpDocumentor | output of sub-spec 4b | `nexus-actors-api` | CNAME → `nexus-actors-api.pages.dev` |

**Why Cloudflare Pages for all three:**
- Edge-cached worldwide; consistent fast TTFB across all 3.
- `_redirects` file on each project for path-level redirects (Docusaurus moves + the cross-subdomain migration).
- Free tier sufficient for V1 traffic on all three.
- Apex domain support via CNAME flattening (no GitHub A-record pinning).
- One Cloudflare account for DNS + cert + hosting + (optional later) Cloudflare Web Analytics — cookieless, no banner needed (consistent with §9.7).
- Built-in preview deployments per PR — each `docs/v1-launch` branch push produces 3 preview URLs (one per site).

**Deployment workflow per site** (`.github/workflows/`):
- `landing-deploy.yml` — on push to `main` affecting `landing/**`, run Astro build, deploy to Cloudflare Pages via `cloudflare/pages-action`.
- `docs-deploy.yml` — on push affecting `website/**`, run Docusaurus build, deploy.
- `api-deploy.yml` — on push affecting `packages/**/src/**` or `phpdoc.dist.xml`, run phpDocumentor in Docker, deploy.

Branch protections: `main` builds deploy to production subdomains; `docs/v1-launch` branch builds deploy to preview URLs (`v1-launch.<project>.pages.dev`).

**DNS / cert ownership:** user owns the DNS zone, transferred to (or managed by) Cloudflare. Cloudflare manages certs for apex + all subdomains. Three sites, one platform, one cert authority.

### 8.4.1 Per-project Cloudflare Pages setup checklist

For each of the 3 projects, the sub-spec plan must establish:

- **Secrets in GitHub Actions repo:** `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID`. Single token works across all 3 projects.
- **Per-project env vars** (Cloudflare Pages dashboard): `NODE_ENV=production` for prod, `NODE_ENV=preview` for branch previews; `SITE_URL` per environment so `<link rel="canonical">` and OG `og:url` differ between preview and prod URLs.
- **Preview-deploy-comment GitHub Action:** `cloudflare/pages-action@v1` (pinned SHA) posts the preview URL as a PR comment on every `docs/v1-launch` branch push — essential for the user's per-page review workflow.
- **DNS records (in Cloudflare zone):** apex `nexusactors.com` uses CNAME flattening to `nexus-actors-landing.pages.dev`; `docs.nexusactors.com` CNAME → `nexus-actors-docs.pages.dev`; `api.nexusactors.com` CNAME → `nexus-actors-api.pages.dev`. **Cloudflare proxy mode (orange-cloud) MUST be ON** for all three records — Pages requires it.
- **Build commands per project:** `landing/`: `npm run build` → output `landing/dist`. `website/`: `npm run build` → output `website/build`. API: `docker compose run phpdoc` → output `build/api`.
- **Branch protection on `docs/v1-launch`:** required reviewers = user; required checks = lint + test + lychee + axe + verify-doc-snippets; force-push disabled.

### 8.4.2 phpDocumentor extension-point fallback (per consistency review finding 9)

§8.1 assumes phpDocumentor v3 exposes its descriptor pipeline via Symfony event-bus subscribers (the modern v3 architecture). If sub-spec 4a's plan finds the public extension API is too narrow, the fallback is to post-process phpDocumentor's cached descriptor XML/JSON output BEFORE the Twig render phase — a wrapper script in Docker reads the cache, mutates template-parameter data into the descriptor tree, writes back, then triggers `phpdoc transform`. This adds ~3 days to the plugin estimate but does not change the deliverable. The Day-3 spike checkpoint in sub-spec 4a's plan resolves which path applies.

### 8.5 Cross-linking — narrative docs ↔ API reference (per user directive)

Bidirectional linking between `docs.nexusactors.com/...` (narrative) and `api.nexusactors.com/<class>` (auto-generated API reference). Three mechanisms:

**A. Manual front-door links** (mandatory). Each of the 25 hand-curated front-door pages in Docusaurus `Reference/` (§8.3 item 4) ends with a "Full API reference" link to `api.nexusactors.com/classes/<FQCN>.html`. Verified by `bin/verify-doc-snippets` extended to check these specific links.

**B. Inline class-name auto-linking via remark plugin** (sub-spec 6 deliverable). A Docusaurus remark plugin scans for backtick-wrapped class names matching a known catalog (the 25 front-door classes + their immediate cousins, plus all exception classes from §7.7) and rewrites them as auto-links to `api.nexusactors.com/<class>`. The catalog ships as `api-classes.json` generated by sub-spec **4a** (the phpDocumentor extension is the canonical source of "what classes exist and where do they live on the API site"). The remark plugin reads this catalog at build time.

Examples of auto-linked references:
- `` `ActorRef` `` → `<a href="https://api.nexusactors.com/classes/Monadial-Nexus-Core-Actor-ActorRef.html">ActorRef</a>` (in narrative prose).
- `` `MailboxClosedException` `` (exception name) → links to its API page (in `reference/exceptions.md` or in failure-modes sections).

The plugin DOES NOT touch backticks inside fenced code blocks (those are syntax, not references). It DOES touch inline backticks in prose. It DOES NOT touch class names that don't appear in `api-classes.json` (avoids false links).

**C. Reverse direction (API ref → narrative)** — phpDocumentor's class-page footer (Twig override from sub-spec 4a) renders a "See also" block fed from a per-class `@see` annotation in PHPDoc. Top-25 front-door classes get an explicit `@see https://docs.nexusactors.com/reference/...` annotation pointing back to their narrative page during the docblock-backfill pass (sub-spec 4b deliverable). Other classes have no reverse link by default; that's acceptable for V1.

**Verification:** `lychee` (§9.3) catches broken cross-site links from `docs.nexusactors.com` into `api.nexusactors.com` (and vice-versa) at CI time. New success criterion #18 in §14.

---

## 9. Cross-cutting infrastructure

### 9.1 Accessibility (WCAG 2.1 AA)
- Target: WCAG 2.1 AA on all pages.
- `axe-core` runs in CI on the full `website/docs/**/*.md` corpus + every marketing page; treats critical violations as build failures.
- Mermaid diagrams ship with `alt`/`aria-label` text (caption mandated in style guide).
- Color contrast verified in light + dark modes.

### 9.2 SEO / OpenGraph / sitemap / canonical (multi-host)
- Per-page `<title>` + `<meta description>` (style-guide-enforced on all 3 sites).
- OG image per marketing page generated at Astro build time; per top-of-section docs page generated at Docusaurus build time (shared script in `nexus-brand/scripts/og-gen.mjs`).
- Twitter Card meta per marketing page.
- **Per-site `sitemap.xml`** generated by each site's build (`@astrojs/sitemap` for Astro; `@docusaurus/plugin-sitemap` for Docusaurus; phpDocumentor generates its own).
- **Sitemap-index `sitemap.xml`** on apex (`nexusactors.com/sitemap.xml`) lists all three sub-sitemaps.
- Canonical URL strategy per §5.2 (marketing vs developer pages distinct + self-canonical; explicit ban on copy-paste between them).
- `robots.txt` on apex points to the sitemap-index.
- Search Console verification meta tag wired post-launch (all 3 subdomains registered as separate properties).

### 9.3 Link-rot detection
- `lychee` runs in CI on the built HTML site; treats broken internal links + broken cross-site links to `api.nexusactors.com` as errors.

### 9.4 Mobile, dark mode, print
- Breakpoints ≥320 / ≥768 / ≥1024 px.
- Dark mode tuned Prism theme (`oneDark`).
- Print stylesheet for `/stability` and `/reference/*`.

### 9.5 Mermaid policy
- Mandatory list in §7.6.
- Color tokens shared with Docusaurus theme.
- Every diagram has caption + alt text.
- Dark-mode-safe color overrides validated in CI by axe-core contrast checks.

### 9.6 Help surface
- GitHub Discussions at `github.com/nexus-actors/nexus/discussions`.
- Footer link + FAQ callout + `/adoption` Support Tiers section all point here.

### 9.7 No analytics in V1
Per user decision. No cookie banner required.

### 9.8 Image / asset strategy
- `website/static/img/{marketing,diagrams,screenshots}/`.
- SVG-first; PNG only for screenshots.
- No third-party logos (V1 hard rule #6 + finding 19).
- `/comparison` rows get text/icon-only visual markers (sub-spec 1 `ComparisonRowMarker` component).
- Favicon set: 16/32/180 + maskable + Apple touch + dark variant.

### 9.9 Deploy strategy

**Atomic cut-over PR.** Per user decision (post-second-pass review): the atomic-PR risk is accepted because the user personally reviews every doc page with AI-assisted iterative fixes during build-up. The PR-size review-fatigue concern doesn't apply when the reviewer is the same person who shepherded each page through drafting.

Mechanism:
- Sub-spec 0 lands directly on `main` first (small, low risk, codebase change only).
- Sub-specs 1, 2, 3, 4a, 4b, 5, 6 land sequentially on a long-lived feature branch `docs/v1-launch` (still individually-commit-reviewable on that branch, even though they merge to `main` together).
- At promotion: **single merge commit** from `docs/v1-launch` to `main`.
- Production cut-over is atomic; review continuity is via the worktree branch on which the user reviews each page as it lands.

### 9.10 Encoding / i18n (closes coverage finding #29)
- All `.md` files are UTF-8 (style-guide rule + EditorConfig).
- Markdownlint rule bans smart quotes (`"…"` `'…'`) inside ``` blocks.
- Docusaurus `i18n.locales: ['en']` — single locale for V1; multi-language deferred.

### 9.11 Skipped (deferred to post-V1)
Algolia/DocSearch; Edit-on-GitHub; runnable sandboxes; versioned docs; Symfony/Laravel integration; social proof; analytics; RSS feed; CI-driven test-count badges.

---

## 10. Phasing and sequencing

```
  STAGE 1 — Platform (lands on main)
  ┌─────────────────────────────────────────────────────────────────┐
  │ Sub-spec 0: Platform bump                                       │
  │ Swoole 6.2.0-rc1 → 6.2.1   PHP base pinned to 8.5.7   24 pkgs   │
  │ Owner: user                                                     │
  └─────────────────────────────────────────────────────────────────┘
                              │
                              ▼ merge to main
                              │
  STAGE 2 — Independent foundations (parallel; all on docs/v1-launch)
  ┌──────────────────┐  ┌──────────────────┐  ┌────────────────────┐
  │ Sub-spec 4a:     │  │ Sub-spec 2: IA   │  │ Sub-spec 5 P1:     │
  │ phpDoc extension │  │ + moves          │  │ Style guide        │
  │ (plugin + Twig)  │  │ + verify-script  │  │                    │
  │ emits            │  │                  │  │                    │
  │ api-classes.json │  │                  │  │                    │
  └──────────────────┘  └──────────────────┘  └────────────────────┘
            │                    │                     │
            └────┬───────────────┴────────────┐        │
                 ▼                            │        │
  STAGE 3 — Theme & chrome (needs 2 + 4a's catalog)    │
                 ┌──────────────────────────────────┐  │
                 │ Sub-spec 6: Theme + Chrome       │  │
                 │ (tokens.css, OG gen, search,     │  │
                 │  badges, class-name remark       │  │
                 │  plugin from api-classes.json,   │  │
                 │  a11y/lychee/mobile CI)          │  │
                 └──────────────────────────────────┘  │
                              │                        │
                              ▼                        │
  STAGE 4 — Content + API ref (parallel — disjoint files; both need 5-P1's style guide)
  ┌──────────────────┐  ┌────────────────────┐   ┌──────────────────┐
  │ Sub-spec 3:      │  │ Sub-spec 5 P2:     │   │ Sub-spec 4b:     │
  │ 38 NEW pages     │  │ Rewrite 84 EXISTING│   │ API ref autogen  │
  │ + diagrams       │  │ pages (voice/      │   │ + top-25 backfill│
  │ (NEW files only) │  │  structure)        │   │ + @internal tags │
  │ — uses 6's OG    │  │ (EXISTING files    │   │ — uses 6 tokens  │
  │   gen + class-   │  │  only)             │   │ — uses 4a plugin │
  │   link plugin    │  │                    │   │                  │
  └──────────────────┘  └────────────────────┘   └──────────────────┘
                              │
                              ▼
  STAGE 5 — Marketing  (uses content, OG, style guide)
                       ┌─────────────────────┐
                       │ Sub-spec 1:         │
                       │ Landing + 7 topic   │
                       │ landings + OG       │
                       └─────────────────────┘
                              │
                              ▼
  STAGE 6 — Cut-over
                ┌─────────────────────────────────┐
                │ Drift/audit read-through        │
                │ Merge docs/v1-launch → main     │
                │ = promotion moment              │
                └─────────────────────────────────┘

EDGES (explicit, no implicit deps):
  0 → 4a                        (4a uses Docker on bumped platform)
  0 → {2, 5-P1, 6}              (everyone needs bumped platform)
  2 → {1, 3, 5-P2, 4b, 6}       (everyone needs final URL structure)
  5-P1 → {1, 3, 5-P2}           (style guide gates all prose)
  4a → 6                        (api-classes.json from 4a → 6's class-name remark plugin)
  4a → 4b                       (4b runs the extended phpDocumentor)
  6 → {1, 3, 4b}                (OG generator for 1; verify-script + Mermaid policy + class-link plugin for 3; tokens.css for 4b)
  3 ∥ 5-P2                      (disjoint files: 3 = 38 NEW pages, 5-P2 = 84 EXISTING pages; no merge conflict)
  {1, 3, 4b, 5-P2} → cut-over   (all merge into v1-launch before promotion)

NO CYCLES (verified):
  4a → 4b   ✓
  4a → 6 → 4b   ✓
  Tokens flow:    6 → 4b  (one direction only)
  Catalog flow:   4a → 6  (one direction only)
```

**Critical path:** 0 → {2, 4a, 5-P1} (parallel) → 6 → {3, 5-P2, 4b} (parallel) → 1 → cut-over.

**Realistic effort with 3–4 person team:** 14–18 weeks. (Earlier "10–14" estimate predated: (a) Phase-2 baseline doubling from 43→84 pages; (b) Astro landing site as separate project; (c) `nexus-brand` shared package; (d) 3 Cloudflare Pages projects; (e) cross-linking infrastructure across 3 mechanisms. Plan-readiness review found 22–31 dev-week linear sum collapses to ~10–14 on parallel DAG — that's still valid for the DAG itself, but the dev-weeks of CONTENT (sub-spec 3 + 5-P2) collectively grew, pushing the parallel critical path to 14–18 weeks.)

User reviews each page on the `docs/v1-launch` branch as it lands — atomic cut-over PR is reviewable in this model because the user has already reviewed contents pre-cut-over.

**If schedule must compress** (per risk 2 + risk 14): candidate scope cuts are (a) sub-spec 6's class-name remark plugin defers to V1.1 (manual front-door links still ship in 4b); (b) sub-spec 5 Phase 2 limits voice rewrite to the 30 highest-traffic pages, remaining ~54 get stale-claim + frontmatter only.

---

## 11. Resolutions (from brainstorming)

1. Sub-spec 4 code changes — APPROVED to bundle (now in sub-spec 4b).
2. Stability matrix — user decided per package (§12).
3. `/comparison` — APPROVED with roadmap pointer + visual markers.
4. Best Practices moves — APPROVED (3 → Guides).
5. Copywriting depth — FULL PASS.
6. Sub-spec 6 (Theme & Site Chrome) — APPROVED.
7. Help surface — GitHub Discussions.
8. Analytics — NONE for V1.
9. Social proof — SKIP for V1.
10. **phpDocumentor approach — EXTEND** (per user directive). Sub-spec 4 split into 4a (extension) + 4b (autogen run).
11. **Deploy strategy — atomic cut-over PR with per-sub-spec drafts on `docs/v1-launch` branch.** User reviews each page on the branch with AI-assisted iterative fixes. Atomic PR is reviewable in this model because content has already been reviewed pre-cut-over.
12. **Sub-spec 0 owned by user** — first thing to land, on `main` standalone, before any other sub-spec starts.
13. **PHP version: 8.5.7** (current floor). Already in composer.json; spec text updated.
14. **phpDocumentor + Nexus extension run in Docker** per CLAUDE.md convention. No host PHP / host phpDocumentor.
15. **Sub-spec 3 ∥ Sub-spec 5 Phase 2 — parallel via disjoint file sets.** 3 = 38 NEW pages; 5-P2 = 84 EXISTING pages. No file overlap → no merge conflict. Voice continuity guaranteed by 5-Phase-1's style guide gating both. Final audit pass catches drift.
16. **Cross-linking — narrative ↔ API ref bidirectional, three mechanisms (§8.5).** Manual front-door links + remark plugin (auto-links inline `` `ClassName` ``) + reverse `@see` annotation on top-25 classes.
17. **Package count = 24** (matches CLAUDE.md). Any "23" reference in agent reports refers to packages with `src/` and is wrong as a project-wide count.
18. **Site split into 3 subdomains** — `nexusactors.com` (Astro landing), `docs.nexusactors.com` (Docusaurus), `api.nexusactors.com` (phpDocumentor). Shared design tokens via `nexus-brand/` package.
19. **All 3 sites hosted on Cloudflare Pages** (per user directive). One platform, three projects, three CI workflows.
20. **Subdomain migration redirect** — `nexusactors.com/docs/*` → `https://docs.nexusactors.com/*` via Cloudflare `_redirects` 301 on the landing project. All path segments preserved.

---

## 12. Stability matrix

All Stable except `nexus-cluster`. ZTS-dependent packages clearly annotated.

| Package | Status | Note |
|---|---|---|
| nexus-core | Stable | |
| nexus-runtime | Stable | |
| nexus-runtime-fiber | Stable | |
| nexus-runtime-swoole | Stable | Swoole 6.2.1+ |
| nexus-runtime-step | Stable | |
| nexus-app | Stable | |
| nexus-serialization | Stable | |
| nexus-logger | Stable | |
| nexus-psalm | Stable | |
| nexus-persistence | Stable | |
| nexus-persistence-dbal | Stable | |
| nexus-persistence-doctrine | Stable | |
| nexus-http | Stable | |
| nexus-http-ws | Stable | |
| nexus-http-auth | Stable | Bearer + JWT only |
| nexus-http-toolkit | Stable | |
| nexus-http-server-swoole | Stable | Swoole 6.2.1+ |
| nexus-http-server-swoole-threads | Stable | **ZTS PHP + Swoole 6.2.1+** — see `operations/zts-php-setup.md` |
| nexus-doctrine-dbal | Stable | |
| nexus-doctrine-orm | Stable | |
| nexus-worker-pool | Stable | |
| nexus-worker-pool-swoole | Stable | **ZTS PHP + Swoole 6.2.1+** — see `operations/zts-php-setup.md` |
| nexus-cluster | **Experimental** | Contracts only; no TCP transport implementation yet |

**ZTS packaging reality (closes second-pass finding 10):** ZTS PHP 8.5 is not in Homebrew default or Debian/Ubuntu/RHEL package managers. `operations/zts-php-setup.md` documents the actual install paths (Docker base image, manual build, shivammathur/setup-php with `--ts`). The `/stability` page links there from the ZTS-row annotation. "Stable" is preserved because the package itself is stable; the platform availability caveat is surfaced honestly.

---

## 13. Sub-spec 0 — Platform bump (FIRST TO LAND)

**Per user directive: sub-spec 0 ships first. No downstream sub-spec starts until 0 is on `main`.**

**Real delta** (PHP 8.5.7 is the current floor; `composer.json` already declares `"php"` constraints across all 24 packages — but constraints are inconsistent: some `">=8.5"`, some `"^8.5"`):

1. **Swoole RC → stable.** `docker/Dockerfile` Swoole-build stage: change `git clone --branch v6.2.0-rc1` to `v6.2.1`.
2. **Pin PHP base image to 8.5.7.** `docker/Dockerfile` base + base-zts stages: pin to the 8.5.7 patch release rather than a floating 8.5 tag, so CI is reproducible.
3. **Add `ext-swoole` version constraint — affects only 4 packages.** Only `nexus-runtime-swoole`, `nexus-http-server-swoole`, `nexus-http-server-swoole-threads`, and `nexus-worker-pool-swoole` currently declare `"ext-swoole": "*"`. Change those four to `"ext-swoole": ">=6.2.1"` so consumers fail-fast on old Swoole. No other packages need touching.
4. **Normalize PHP composer constraint** — current mix of `">=8.5"` and `"^8.5"` across packages is inconsistent. Standardize on `">=8.5.7"` across all 23 packages with `src/` + the `nexus` meta-package = 24 files total.
5. **CI matrix** verified on PHP 8.5.7 / Swoole 6.2.1.
6. **Full test suite green** on the bumped baseline across all 24 packages (Fiber + Swoole + Worker Pool + Cluster + Persistence integration suites).

**Owner:** user (per directive).
**Effort:** 3–5 days.

**Package count is 24** — matches `CLAUDE.md` opening paragraph ("monorepo with 24 packages under `packages/`"). 23 packages have `src/`; the `nexus` umbrella meta-package has no `src/` but still has its own `composer.json` and counts as the 24th package. Documentation pages exist for the 23 with-src packages plus a `packages/nexus.md` for the meta-package (§14 #8).

---

## 14. Success criteria (concrete, reproducible, 3-site)

The revamp is complete when:

1. All eight sub-spec implementation plans (0, 1, 2, 3, 4a, 4b, 5, 6) are merged.
2. **All 3 Cloudflare Pages projects deploy green from `main`**: Astro landing, Docusaurus docs, phpDocumentor API ref.
3. **Docusaurus build** passes with zero broken-link errors (`onBrokenLinks: 'throw'`).
4. **Astro build** passes with zero broken `<a href>` to docs/api subdomains (verified by `lychee`).
5. `lychee` link-check across **all 3 sites' built HTML** reports zero broken internal or cross-site links.
6. `bin/verify-doc-snippets` extracts and lints every ```php block in the **full `website/docs/**/*.md` corpus** (no sampling); zero failures.
7. `api.nexusactors.com` serves the auto-generated reference; all top-25 front-door pages link in correctly; phpDocumentor renders `<T>` template parameters on the 5 smoke-test pages (HTML grep confirms).
8. Every package in `packages/` (all 24) has a sidebar entry + a corresponding `packages/<name>.md` page.
9. `axe-core` runs in CI on **the full doc corpus + every Astro landing page + every phpDocumentor front-door page** and reports zero critical violations.
10. **Lighthouse on all 3 sites:** profile = Lighthouse 12+ mobile, simulated slow 4G, median of 3 runs. ≥ 90 across performance/accessibility/best-practices/SEO on Astro landing + every topic landing; ≥ 85 on a sample of 5 docs pages (lower bar because Docusaurus ships heavier JS).
11. **Mobile viewport test at 375px** on **Astro landing + every topic landing + every Operations docs page** — no horizontal scroll, all CTAs thumb-reachable.
12. Dark mode visual review passes on the same set as #11.
13. `reference/exceptions.md` documents 41 exceptions; ≥ 26 have full Cause/Resolution entries.
14. `operations/troubleshooting.md` has 15 entries; `upgrade.md` has 12 entries; `reference/gotchas.md` has 21 entries.
15. **Full-corpus stale-claim scan** (deterministic): grep for `"PHP 8.1"`, `"Swoole 5"`, `"Swoole 6.2.0-rc"`, `"closure-factory ask"`, `"localhost:3000/docs"` (old URLs), etc., returns zero matches across all 3 sites' source.
16. Every Core Concepts page **on the §2 rule #7 list** has a "Failure modes" subsection.
17. Every page in §7.5 has a decision-tree block; every page in §7.6 has its listed diagrams (17 Mermaid + 1 SVG).
18. **Cross-linking verified** (§8.5): `lychee` confirms every `docs.nexusactors.com/reference/*` page links to `api.nexusactors.com`; class-name auto-link plugin produces at least 50 working cross-links across the docs corpus; top-25 API pages have reverse `@see` links back to narrative.
19. **Subdomain migration 301s** verified by `lychee` on representative old URLs (`nexusactors.com/docs/intro`, `nexusactors.com/docs/core-concepts/actors`, etc.) — all resolve to `docs.nexusactors.com/...` with HTTP 301.
20. **Marketing pages reviewed by at least one reader outside the docs author** matching a target persona (CTO / Senior PHP engineer / Ops engineer). Sign-off recorded in the merge PR.
21. **Brand consistency check** at cut-over: one full page screenshot from each site placed side-by-side; visual review confirms shared header style, color, typography, button style.
22. The promotion CTA can link to `/why-nexus` + `/adoption` and stand on its own.

---

## 15. Risk register (expanded)

| # | Risk | Likelihood | Impact | Trigger / early-warning | Mitigation |
|---|------|------------|--------|-------------------------|------------|
| 1 | phpDocumentor extension takes longer than estimated 1.5–2 weeks | Med | Med | Smoke-test pages don't render `<T>` after 1 week | Sub-spec 4a plan includes a checkpoint at 1 week; if behind, descope to plugin-only (skip Twig overrides — generics show in raw form but at least appear) |
| 2 | Sub-spec 5 Phase 2 slips by 2–3× | High | High | Per-page DoD (§7.4) shows <50% pages passing at week N midpoint | Reduce scope: rewrite the 30 highest-traffic pages to full voice/structure; the remaining ~54 pages get stale-claim fixes + frontmatter + file-path code titles but skip voice rewrite |
| 3 | Sub-spec 0 (Swoole bump) regresses a package's CI | Med | High | CI matrix failures on Swoole 6.2.1 | Per-package incremental upgrade; revert offending package until fixed; pin to last-known-good 6.x.y if blocker |
| 4 | **Swoole 6.2.1 is yanked** (security or stability issue) | Low | High | Upstream security advisory | Pin to the next stable in the 6.x series; update `/stability` ZTS row |
| 5 | `nexus-actors-api` Cloudflare Pages project hits build-minute or file-count limit | Low | Med | CF Pages dashboard quota alert | Free tier allows 500 builds/month + 20k files per deploy; phpDocumentor output for ~440 pages is ~1k files, well under. If hit, upgrade to Pro ($20/mo) |
| 6 | Sub-spec 6 swizzles break on next Docusaurus minor | Med | Low | Docusaurus bump produces build warnings | Pin Docusaurus minor; replay swizzles on bump |
| 7 | 17-diagram pass exceeds time budget | Med | Med | §7.6 list partially shipped at week N | NOT a fallback to "6 diagrams" (that contradicts §14); instead, ship Mermaid skeletons by deadline and refine prose later — diagrams are validating not perfecting |
| 8 | Cross-package links break after splitsh-lite split | Med | Med | Split repos serve broken README links | All README internal links use absolute URLs to `docs.nexusactors.com/...`; no relative cross-package paths |
| 9 | GitHub Discussions adoption slow; Q&A still goes to issues | Med | Low | Issue tracker fills with Q&A | Triage label + redirect template; pin a "Ask in Discussions" issue |
| 10 | OG-image generator slow/blocks CI build | Med | Low | Build time > CI timeout | Cache OG images by frontmatter hash; rebuild only on title/desc change |
| 11 | **`docs/v1-launch` branch grows stale vs `main`** during long build-up | High | Med | Merge conflicts on weekly rebase | Weekly rebase of `docs/v1-launch` onto `main`; resolve conflicts incrementally |
| 12 | **ZTS PHP 8.5 unavailable in major distros at promotion** | Med | Med | shivammathur/setup-php / Homebrew issue tracker | `operations/zts-php-setup.md` documents Docker-base-image fallback; `/stability` annotation already calls this out |
| 13 | **Cluster "Preview" page becomes a magnet for "when will this ship?" issues** | Med | Low | Issue spike on cluster | Page top has explicit "Status: Experimental; no transport implementation; PRs welcome" callout linking to roadmap |
| 14 | **Contributor burnout** — one author rewriting 84 pages + writing 38 new | High | High | Phase-2 + sub-spec 3 velocity dropping; quality declining | Team requires 3–4 owners (per plan-readiness review §B-9); single-owner execution rejected. Mandatory: 1 voice owner for Phase 2 + 1+ assist scribes; weekly drift audit |
| 15 | **`bin/verify-doc-snippets` script is itself the single point of failure** | Low | High | Script breaks on edge-case markdown | Script has its own unit tests (golden-input → expected-output); CI fails fast if script can't run |
| 16 | **phpDocumentor security CVE during V1** forces emergency upstream pin | Low | Med | phpDocumentor advisory | Pin phpDocumentor version; budget 1 day for patch turnaround |
| 17 | **Mermaid dark-mode regressions** on Docusaurus minor bumps | Med | Low | Visual review on dark mode fails | axe-core contrast check catches this in CI |
| 18 | **`/comparison` honest "planned" cells read as Nexus weakness** | Med | Med | CTO feedback in /adoption support tier | Page header sets expectation: "honest comparison; planned items have GitHub issue links; we ship what we say we ship" |
| 19 | **3-site coordination drift** — Astro / Docusaurus / phpDocumentor build/deploy mismatches at promotion | Med | Med | One site live with new content, another with stale | Atomic cut-over: all 3 Cloudflare Pages projects deploy from `main` after `docs/v1-launch` merge; CI gate ensures all 3 builds pass before merge |
| 20 | **Subdomain migration 301 misses an old URL** | Med | High | External blog post / Google index hits 404 on `nexusactors.com/docs/old-path` | Cloudflare `_redirects` uses splat: `/docs/* → https://docs.nexusactors.com/:splat 301`. `lychee` CI tests representative old URLs after deploy |
| 21 | **Astro + Docusaurus + phpDocumentor style drift** despite shared brand tokens | Med | Low | Visual inspection finds inconsistent header, button, or color | `nexus-brand/` is the single source of truth; per-site overrides forbidden in V1; design review at cut-over compares one full page from each site side-by-side |
| 22 | **Cloudflare Pages free-tier limit hit on build count or bandwidth** | Low | Low | Pages quota dashboard alert | Free tier allows 500 builds/month and unlimited bandwidth; V1 traffic is comfortably under. If hit, upgrade to $20/mo Pro plan |
| 23 | **Astro 5 minor bump breaks landing build** between now and promotion | Med | Low | CI fails on Astro upgrade | Pin Astro minor in `landing/package.json`; bump explicitly when needed |

---

*End of design.*
