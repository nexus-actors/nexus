# Audit PDF and Remediation Backlog Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Generate a polished PDF of the complete independent audit and a GitHub-ready Markdown remediation backlog containing one actionable task for each of its 68 findings.

**Architecture:** Treat the existing audit Markdown as the canonical input. A small Node.js builder will parse and validate finding tables, enrich each finding with planning metadata, emit the backlog, and render print-oriented HTML through the repository's installed `marked` package. Local Google Chrome will print the HTML to PDF; Poppler and image tools will provide structural and visual verification.

**Tech Stack:** Node.js 22, `marked`, HTML/CSS paged media, headless Google Chrome, Poppler (`pdfinfo`, `pdftotext`, `pdftoppm`), Ruby/shell integrity checks.

---

### Task 1: Build the finding parser and metadata catalog

**Files:**
- Create: `bin/build-audit-deliverables.mjs`
- Read: `docs/audits/2026-07-16-nexus-independent-audit.md`

**Step 1: Add strict audit parsing**

Read the audit, recognize rows beginning with finding IDs, normalize the two table shapes, and fail unless there are exactly 68 unique IDs with valid `High` or `Medium` severity.

**Step 2: Add explicit planning metadata**

Define a catalog keyed by every finding ID with a concise issue title, effort label, remediation phase, dependencies, and domain-specific test focus. Fail on missing or extra metadata keys.

**Step 3: Run parser validation**

Run:

```bash
node bin/build-audit-deliverables.mjs --validate
```

Expected: `Validated 68 audit findings and 68 planning records.`

### Task 2: Generate the GitHub remediation backlog

**Files:**
- Create: `docs/audits/2026-07-16-nexus-remediation-backlog.md`
- Modify: `bin/build-audit-deliverables.mjs`

**Step 1: Render backlog structure**

Emit a title, usage guidance, effort legend, release policy, phase summary, and one checkbox task heading per finding.

**Step 2: Render complete task contracts**

For every task emit severity, effort, phase, dependencies, problem, impact, implementation scope, acceptance criteria, required tests, documentation/compatibility work, and source audit link.

**Step 3: Generate and validate**

Run:

```bash
node bin/build-audit-deliverables.mjs --backlog
ruby -e 's=File.read(ARGV[0]); ids=s.scan(/^### \[ \] ([A-Z]+-\d+):/).flatten; abort unless ids.length == 68 && ids.uniq.length == 68' docs/audits/2026-07-16-nexus-remediation-backlog.md
```

Expected: generation succeeds and Ruby exits zero.

### Task 3: Create print-oriented audit HTML

**Files:**
- Modify: `bin/build-audit-deliverables.mjs`
- Generate: `/tmp/nexus-independent-audit.html`

**Step 1: Render Markdown with stable heading IDs**

Use `marked` to render the complete source audit. Add a cover block and preserve links, code blocks, tables, and evidence text.

**Step 2: Transform finding tables for print**

Mark finding tables separately from ordinary scorecards. Render each finding row as a readable record with ID/severity header and labeled evidence, impact, and action fields rather than squeezing five columns into portrait pages.

**Step 3: Add paged-media CSS**

Embed A4 margins, typography, restrained severity colors, running header/footer, page counters, repeated table headings, break controls, and print-safe links.

**Step 4: Generate HTML**

Run:

```bash
node bin/build-audit-deliverables.mjs --html /tmp/nexus-independent-audit.html
```

Expected: nonempty standalone HTML containing the complete audit.

### Task 4: Render and structurally verify the PDF

**Files:**
- Create: `docs/audits/2026-07-16-nexus-independent-audit.pdf`

**Step 1: Print with headless Chrome**

Run:

```bash
'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' \
  --headless --disable-gpu --no-pdf-header-footer \
  --print-to-pdf=docs/audits/2026-07-16-nexus-independent-audit.pdf \
  file:///tmp/nexus-independent-audit.html
```

Expected: Chrome writes a nonempty PDF.

**Step 2: Verify PDF metadata and text**

Run:

```bash
pdfinfo docs/audits/2026-07-16-nexus-independent-audit.pdf
pdftotext docs/audits/2026-07-16-nexus-independent-audit.pdf /tmp/nexus-independent-audit.txt
```

Expected: A4 page size, nonzero page count, and extracted text containing `Executive Verdict`, `DDD-001`, `SEC-001`, `Release Gates`, and `Evidence Appendix`.

### Task 5: Visually inspect representative pages

**Files:**
- Generate: `/tmp/nexus-audit-pages/*.png`

**Step 1: Rasterize representative PDF pages**

Use `pdftoppm` to render the cover, scorecard, first finding page, security findings, roadmap, and final evidence page.

**Step 2: Inspect images**

Check for blank pages, clipped text, overlapping labels, unreadable type, broken page headers/footers, and table/card overflow.

**Step 3: Correct and rerender as needed**

Adjust only the builder's HTML/CSS, regenerate HTML/PDF, and repeat structural and visual checks until all sampled pages are clean.

### Task 6: Final deliverable verification

**Files:**
- Verify: `docs/audits/2026-07-16-nexus-independent-audit.pdf`
- Verify: `docs/audits/2026-07-16-nexus-remediation-backlog.md`
- Verify: `bin/build-audit-deliverables.mjs`

**Step 1: Run artifact integrity checks**

Confirm 68 unique backlog tasks, 68 unique source findings, valid internal Markdown structure, ASCII source files, PDF page count, and expected extracted headings.

**Step 2: Review worktree scope**

Use `git status --short` and inspect only the three deliverable paths. Do not revert or include unrelated concurrent changes.

**Step 3: Report results**

Provide direct links to the PDF and Markdown backlog, summarize the page/task counts, state verification evidence, and disclose any remaining rendering or worktree limitations.
