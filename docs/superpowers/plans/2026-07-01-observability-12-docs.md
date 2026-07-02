# Observability — Plan 12: Documentation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Document the observability feature across all three doc tiers — a Docusaurus guide section, per-package pages + phpDocumentor API wiring for the 12 new packages, and an Astro landing page — so users can discover, enable, and extend Nexus observability.

**Architecture:** Docs-only plan. A new Docusaurus **Observability** guide section (overview → quick-start → config → tracing → metrics → custom instrumentation → logs → per-surface → wiring), 12 package reference pages wired into `sidebars.js`, the 12 packages added to `phpdoc.dist.xml` + `bin/build-api-docs.sh` (with corrected counts), and a marketing `observability.astro` page. All Docusaurus ```php snippets must pass `make docs-verify` (php -l + Psalm); use `verify:lint-only` for illustrative fragments and `verify:skip` (with an HTML-comment reason) for partial snippets.

**Tech Stack:** Docusaurus (Markdown), phpDocumentor (`phpdoc.dist.xml` + `bin/build-api-docs.sh`), Astro (`landing/`), Docker. Verification: `make docs-verify` (snippet lint/Psalm), `make docs-api` (phpDoc build).

## Global Constraints

- **Docker only** for verification: `docker compose exec -T php ...`; `make docs-verify` / `make docs-api` run via Docker. `Warning: JIT...` is env noise.
- **Commit policy:** `git -c commit.gpgsign=false commit --no-verify` (GrumPHP hook broken + worktree GPG times out; composer.lock gitignored). NEVER add `Co-Authored-By: Claude`.
- **Snippet verification (hard rule):** every ` ```php ` fenced block in `website/docs/**` is verified by `make docs-verify` (php -l + Psalm). Complete, type-correct examples use plain ` ```php `; illustrative fragments use ` ```php title="..." verify:lint-only `; partial/pseudo snippets use ` ```php title="..." verify:skip ` **with a reason in an HTML comment directly above the fence**. Run `make docs-verify` before each docs commit — it MUST exit 0.
- **Accuracy:** every class name, method signature, package name, and metric/span name in the docs must match the shipped code (Plans 1–11). Cross-check against the source before writing — do NOT invent API.
- **Landing coordination (D22):** `landing/` is under active ultrareview on the `astro-landing-1` branch. Keep the new `observability.astro` **small, self-contained, and additive** (new file + one link on `index.astro`); mirror the structure of the existing `doctrine.astro`. Note in the commit that it needs rebase-coordination with `astro-landing-1`.
- **Markdown/frontmatter:** match the existing docs' frontmatter shape (`sidebar_position`, `title`, optional `related`). Match heading style + admonition usage of neighboring pages.

## Shipped surface to document (verify against source)

- **Packages (12):** `nexus-observability` (contracts/no-op/config/propagation), `nexus-observability-otel` (SDK bridge + `ObservabilityFactory`), `nexus-observability-http` (`ServerSpanMiddleware`, `HttpMetricsListener`), `nexus-observability-persistence` (`TracingEventStore`/`TracingSnapshotStore`/`TracingDurableStateStore`), `nexus-observability-worker-pool` (`TracingWorkerTransport`), `nexus-observability-doctrine` (`DbalPoolMetricsListener`, `OrmPoolMetricsListener`, `TracingDriverMiddleware`), `nexus-observability-logger` (`TraceCorrelationProcessor`), `nexus-observability-swoole` (`SwooleContextRegistrar`, `SwooleAdminMetrics`), `nexus-observability-actor` (`ActorSystemMetrics`).
- **Actor API:** `$ctx->tracer()`, `$ctx->meter()`, `$ctx->currentSpan()`; `ActorSystem::create(..., ?Observability)`; `NexusApp::withObservability(Observability)`.
- **Config:** `ObservabilityConfig::{disabled,enabled,fromEnv}`, `ObservabilityFactory::fromConfig`; standard OTEL_* env vars; disabled-by-default no-op.
- **Metrics catalog** (verify exact names in code): `nexus.actor.messages.processed`, `nexus.actor.message.processing.duration`, `http.server.request.duration`, `http.server.active_requests`, `nexus.persistence.*`, `nexus.worker_pool.*`, `nexus.dbal.pool.*`, `nexus.orm.pool.*`, `swoole.coroutine.*`, `swoole.server.*`, `nexus.actor_system.*`.

---

## Task 1: Docusaurus Observability guide section

**Files:**
- Create: `website/docs/observability/overview.md`, `quick-start.md`, `configuration.md`, `tracing.md`, `metrics.md`, `custom-instrumentation.md`, `logs.md`, `wiring.md`
- Modify: `website/sidebars.js` (add an **Observability** category after HTTP/before Persistence, or after Operations — match neighbouring placement)
- Modify: `website/docs/operations/observability.md` (add a cross-link banner to the new OTEL section; keep the existing PSR-3/log content)

**Content requirements (each page, scaled to topic):**
- **overview.md** — what Nexus observability is (OTEL traces/metrics/logs), the 3-foundation + satellite package model, disabled-by-default/zero-overhead/fail-isolation guarantees, the end-to-end trace picture (HTTP → actor → persistence/SQL/worker) with the span-tree diagram.
- **quick-start.md** — enable in ~5 min: `composer require nexus-actors/observability-otel`, set `OTEL_EXPORTER_OTLP_ENDPOINT`, `NexusApp::create(...)->withObservability(ObservabilityFactory::fromConfig(ObservabilityConfig::fromEnv($_SERVER)))->run(...)`, run a local OTel Collector via docker-compose (include a minimal collector `docker-compose` + `otel-collector-config.yaml` snippet). Complete runnable example → plain ` ```php `.
- **configuration.md** — `ObservabilityConfig` fields + `fromEnv` OTEL_* vars table (OTEL_SERVICE_NAME, OTEL_EXPORTER_OTLP_ENDPOINT/PROTOCOL, OTEL_TRACES_SAMPLER(+_ARG), OTEL_SDK_DISABLED, OTEL_RESOURCE_ATTRIBUTES); sampler options; programmatic builder; disabled-by-default.
- **tracing.md** — actor message spans (Consumer), context propagation via `Envelope::metadata` (W3C Trace Context + Baggage) across local/worker/cluster/HTTP; the request→actor→response single trace; span attributes (metadata-only, D5).
- **metrics.md** — the full metrics catalog table (name, type, unit, key dims — VERIFIED against code); low-cardinality guidance (D11).
- **custom-instrumentation.md** — `$ctx->tracer()`/`$ctx->meter()`/`$ctx->currentSpan()` inside handlers (the user asked for this — make it prominent), and the `Observability` provider elsewhere; zero-cost-when-disabled. Complete handler example → plain ` ```php `.
- **logs.md** — `TraceCorrelationProcessor` stamps trace_id/span_id onto log records; how to register it in the logger pipeline; relation to the existing `TraceContextMiddleware` (OTel processor is the span-id source of truth when observability is enabled).
- **wiring.md** — the component wiring guide (the deferred integration doc): register `ServerSpanMiddleware` + `HttpMetricsListener` in the HTTP pipeline; wrap stores with `Tracing*Store`; wrap the worker transport with `TracingWorkerTransport`; add the DBAL `TracingDriverMiddleware` via `Configuration::setMiddlewares`; subscribe `DbalPoolMetricsListener`/`OrmPoolMetricsListener`; register `ActorSystemMetrics`; on Swoole call `SwooleContextRegistrar::install()` + `SwooleAdminMetrics::register*()` at worker start; add `TraceCorrelationProcessor` to the logger.

- [ ] **Step 1: Verify the API surface** before writing — for each page's code snippets, grep the real signatures/names:
```bash
docker compose exec -T php grep -rn "public function withObservability\|public function fromConfig\|public static function fromEnv" packages/nexus-app packages/nexus-observability-otel packages/nexus-observability
docker compose exec -T php grep -rn "->counter('\|->histogram('\|->upDownCounter('\|startSpan('" packages/nexus-*/src
```
Use ONLY names/signatures that exist. Metric names must match the code exactly.

- [ ] **Step 2: Write the 8 pages** with correct frontmatter (`sidebar_position` ascending; `title`). Use plain ` ```php ` only for COMPLETE, Psalm-valid examples (with `use` imports + valid types); use `verify:lint-only` for fragments; `verify:skip` (+ HTML-comment reason) for YAML/pseudo/partial. Prefer a few complete verified examples over many fragile ones.

- [ ] **Step 3: Wire `sidebars.js`** — add:
```js
    {
      type: 'category',
      label: 'Observability',
      items: [
        'observability/overview',
        'observability/quick-start',
        'observability/configuration',
        'observability/tracing',
        'observability/metrics',
        'observability/custom-instrumentation',
        'observability/logs',
        'observability/wiring',
      ],
    },
```
(Place it near HTTP/Persistence/Operations — match the section grouping.)

- [ ] **Step 4: Cross-link the existing `operations/observability.md`** — add a short admonition at the top pointing to the new OTEL section (keep existing content).

- [ ] **Step 5: Verify snippets** — `make docs-verify` MUST exit 0. Fix any failing snippet (correct the API, or downgrade the fence to `verify:lint-only`/`verify:skip` with a reason). Re-run until clean.

- [ ] **Step 6: Commit**
```bash
git add website/docs/observability website/sidebars.js website/docs/operations/observability.md
git -c commit.gpgsign=false commit --no-verify -m "docs(observability): add OTEL observability guide section (verified snippets)"
```

---

## Task 2: Per-package reference pages + packages sidebar

**Files:**
- Create: `website/docs/packages/observability.md`, `observability-otel.md`, `observability-http.md`, `observability-persistence.md`, `observability-worker-pool.md`, `observability-doctrine.md`, `observability-logger.md`, `observability-swoole.md`, `observability-actor.md` (9 pages — the 3 foundation + 6 satellites; group logically)
- Modify: `website/sidebars.js` (add an **Observability** category to the Packages section)

- [ ] **Step 1: Write the 9 package pages** — mirror the shape of an existing package page (e.g. `website/docs/packages/logger.md`): purpose, install (`composer require nexus-actors/observability-*`), key classes (verified names), a short usage snippet (`verify:lint-only` unless complete), and a link to the wiring guide. Match the existing pages' frontmatter + heading style.

- [ ] **Step 2: Add a Packages → Observability category** to `sidebars.js`:
```js
        {
          type: 'category',
          label: 'Observability',
          items: [
            'packages/observability',
            'packages/observability-otel',
            'packages/observability-http',
            'packages/observability-persistence',
            'packages/observability-doctrine',
            'packages/observability-worker-pool',
            'packages/observability-logger',
            'packages/observability-swoole',
            'packages/observability-actor',
          ],
        },
```

- [ ] **Step 3: Verify** — `make docs-verify` exits 0.

- [ ] **Step 4: Commit**
```bash
git add website/docs/packages website/sidebars.js
git -c commit.gpgsign=false commit --no-verify -m "docs(observability): add per-package reference pages + packages sidebar category"
```

---

## Task 3: phpDocumentor API wiring

**Files:**
- Modify: `phpdoc.dist.xml` (add the 12 `nexus-observability*/src` paths; fix the "22 of 25 packages" comment)
- Modify: `bin/build-api-docs.sh` (add the 12 packages to the `for pkg in ...` merge list; fix the hardcoded "22 packages" count in the generated index)

- [ ] **Step 1: Add paths to `phpdoc.dist.xml`** — add under `<source>`:
```xml
                <path>nexus-observability/src</path>
                <path>nexus-observability-otel/src</path>
                <path>nexus-observability-http/src</path>
                <path>nexus-observability-persistence/src</path>
                <path>nexus-observability-worker-pool/src</path>
                <path>nexus-observability-doctrine/src</path>
                <path>nexus-observability-logger/src</path>
                <path>nexus-observability-swoole/src</path>
                <path>nexus-observability-actor/src</path>
```
Update the header comment count (was "22 of 25"; now 22+9 = 31 of the relevant set — recount from the actual `<path>` entries and state it accurately).

- [ ] **Step 2: Add packages to `bin/build-api-docs.sh`** — append the 9 observability package names to the `for pkg in nexus-core ... ` list so their `src` is merged. Update the hardcoded "22 packages" string in the generated index (line ~236) to the correct total (recount the `for pkg` list length).

- [ ] **Step 3: Build** — `make docs-api` MUST succeed and include the observability classes. Spot-check the output dir (`build/api-nexus`) contains observability class pages.

- [ ] **Step 4: Commit**
```bash
git add phpdoc.dist.xml bin/build-api-docs.sh
git -c commit.gpgsign=false commit --no-verify -m "docs(observability): wire 9 observability packages into phpDocumentor API build"
```
> Note: PHPDoc quality on the new classes is already high (every class has `@psalm-api` + docblocks from Plans 1–11). If `make docs-api` surfaces classes with missing docblocks, add concise class-level PHPDoc to those files (do not touch behavior).

---

## Task 4: Astro landing page (coordinate with `astro-landing-1`)

**Files:**
- Create: `landing/src/pages/observability.astro`
- Modify: `landing/src/pages/index.astro` (one feature card/link to the new page)

- [ ] **Step 1: Inspect the parallel page** — read `landing/src/pages/doctrine.astro` and `http.astro` to match the exact layout, component imports, head/meta, and styling conventions. The new page must reuse the same components (no new design system).

- [ ] **Step 2: Write `observability.astro`** — mirror `doctrine.astro`'s structure: hero, feature sections (distributed tracing, metrics, logs correlation, zero-overhead-when-disabled, OTLP/collector), a code snippet (the `withObservability` one-liner), links to the Docusaurus observability guide. Emerald palette only (repo convention — no indigo). Keep it self-contained.

- [ ] **Step 3: Add one link on `index.astro`** — a feature card/nav entry pointing to `/observability`, matching how `doctrine`/`http` are linked. Minimal, additive diff.

- [ ] **Step 4: Build** — if the toolchain is available in the environment, build the landing site to confirm no breakage:
```bash
cd landing && (pnpm build 2>/dev/null || npx --yes astro build 2>/dev/null) ; cd -
```
If the Astro/pnpm toolchain isn't runnable here (Node version constraints noted in prior sessions), at minimum verify the file parses (matching import paths + component names from the sibling pages) and record that a full build should be run on the `astro-landing-1` branch.

- [ ] **Step 5: Commit** (flag the coordination need)
```bash
git add landing/src/pages/observability.astro landing/src/pages/index.astro
git -c commit.gpgsign=false commit --no-verify -m "docs(observability): add landing page (coordinate/rebase with astro-landing-1 before merge)"
```

---

## Task 5: Root docs (CLAUDE.md + Makefile)

**Files:**
- Modify: `CLAUDE.md` (package dependency graph + package count/list: add the 12 observability packages + the `nexus-core → nexus-observability` edge; note actor `$ctx->tracer()/meter()/currentSpan()`)
- Modify: `Makefile` (add a `test-observability` convenience target if it fits the existing pattern; add any new packages to relevant `.PHONY`/matrices — optional, match existing style)

- [ ] **Step 1: Update `CLAUDE.md`** — in the Package Dependency Graph, add the observability layer (foundational `nexus-observability` + `nexus-core → nexus-observability`, and the satellites → their surface + observability). Update the "24 packages" / package-count prose to the new total. Add a one-line note under the Actor Model section that handlers can use `$ctx->tracer()`/`meter()`/`currentSpan()` for custom telemetry.
> `CLAUDE.md` may have been edited outside this branch — re-read it first and make a surgical, additive edit that fits the current content.

- [ ] **Step 2: Makefile** (optional) — if there's a per-area test target pattern, add:
```makefile
test-observability: ## Observability package unit tests
	docker compose exec -T php vendor/bin/phpunit packages/nexus-observability/tests/Unit packages/nexus-observability-otel/tests/Unit packages/nexus-observability-http/tests/Unit packages/nexus-observability-persistence/tests/Unit packages/nexus-observability-worker-pool/tests/Unit packages/nexus-observability-doctrine/tests/Unit packages/nexus-observability-logger/tests/Unit packages/nexus-observability-actor/tests/Unit
	docker compose exec -T php-swoole vendor/bin/phpunit packages/nexus-observability-swoole/tests/Unit
```
Add `test-observability` to `.PHONY`. If the Makefile's target style differs, match it.

- [ ] **Step 3: Verify** — `make docs-verify` still exits 0 (CLAUDE.md isn't under `website/docs`, but confirm nothing else broke); the observability package suite still passes.

- [ ] **Step 4: Commit**
```bash
git add CLAUDE.md Makefile
git -c commit.gpgsign=false commit --no-verify -m "docs(observability): update CLAUDE.md dependency graph + package list + Makefile target"
```

---

## Self-Review (plan author)

- **Spec coverage (Plan 12 slice — §15 documentation, D22):** Docusaurus guide (overview/quick-start/config/tracing/metrics/custom-instrumentation/logs/wiring) ✓ (Task 1); per-package pages + sidebar ✓ (Task 2); phpDocumentor wiring for all new packages + corrected counts ✓ (Task 3); Astro landing page (coordinate with `astro-landing-1`) ✓ (Task 4); root CLAUDE.md/Makefile ✓ (Task 5); `make docs-verify` gate on all ```php snippets ✓. Per-package READMEs remain out of scope (D22).
- **Placeholder scan:** none — each task has concrete files, content requirements, exact sidebar/config edits, and verification commands. API surface must be grepped/verified before writing (Task 1 Step 1) — no invented signatures.
- **Consistency:** package names, class names, and metric/span names to document are enumerated above and must match Plans 1–11 exactly; sidebar entries match the created file slugs; phpDoc paths match the 9 observability package dirs.

## Downstream: NONE — this is the final plan. After Task 5, the observability feature (11 code plans + docs) is complete on `feat/observability`. Remaining tracked follow-ups (separate future work): EntityRefFactory entity-repo spans (base-package seam, D25); OTLP log-record export; Fiber `FiberBoundContextStorage` warning fix; WS spans.
