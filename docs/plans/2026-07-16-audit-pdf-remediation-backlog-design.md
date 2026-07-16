# Audit PDF and Remediation Backlog Design

**Date:** 2026-07-16
**Status:** Approved

## Objective

Publish the independent Nexus audit in two coordinated forms:

1. A professionally typeset PDF optimized for reading, review meetings, and distribution.
2. A GitHub-ready Markdown backlog that turns every audit finding into an actionable engineering task.

The PDF is the reader-facing assessment. The Markdown backlog is the implementation source of truth.

## PDF Design

The PDF will render the complete independent audit without adding unverified claims. It will use:

- A4 portrait pages with print-safe margins.
- A restrained technical-report palette with distinct High and Medium severity treatments.
- A cover page, contents, section hierarchy, page headers, footers, and page numbers.
- Compact scorecards and finding tables that wrap without clipping.
- Repeated table headers where the renderer supports them.
- Monospace treatment for symbols, commands, paths, and finding IDs.
- Page-break controls that keep headings with their following content.

The PDF will summarize remediation through the audit roadmap and release gates. Detailed task specifications stay in the Markdown backlog to avoid making the PDF unnecessarily difficult to navigate.

## Backlog Design

The backlog will contain exactly one task for every finding ID in the audit. Tasks will be grouped by the audit's remediation phases and technical domains.

Each task will contain:

- Finding ID and concise issue title.
- Severity and suggested effort range.
- Problem statement and production impact.
- Implementation scope and technical guidance.
- Dependencies and sequencing notes.
- Acceptance criteria.
- Required automated and operational tests.
- Documentation and compatibility requirements.
- A link back to the source audit section.

Markdown checkboxes and stable headings will make tasks directly reusable as GitHub issues or an issue-import source.

## Effort Model

Effort labels are planning ranges, not commitments:

- **S:** up to 3 engineer-days.
- **M:** approximately 4-10 engineer-days.
- **L:** approximately 2-5 engineer-weeks.
- **XL:** approximately 1-3 engineer-months and likely requires an RFC or staged rollout.

Cross-cutting work may share implementation effort, so task estimates must not be summed mechanically.

## Validation

The deliverables are complete when:

- All 68 unique audit IDs occur exactly once as backlog task headings.
- Every task includes acceptance criteria and required tests.
- The PDF contains the complete audit and has a nonzero page count.
- PDF pages are visually inspected for clipping, blank pages, unreadable tables, and broken code blocks.
- The audit's BASE/DIRTY evidence distinction remains intact.
- Generated artifacts do not overwrite unrelated worktree changes.
