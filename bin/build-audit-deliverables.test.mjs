import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';

import {
    buildBacklog,
    buildPrintHtml,
    parseFindings,
    planningMetadata,
    validatePlanningMetadata,
} from './build-audit-deliverables.mjs';

const auditPath = new URL('../docs/audits/2026-07-16-nexus-independent-audit.md', import.meta.url);
const backlogPath = new URL('../docs/audits/2026-07-16-nexus-remediation-backlog.md', import.meta.url);
const audit = await readFile(auditPath, 'utf8');

test('extracts exactly 68 unique findings from both supported table shapes', () => {
    const findings = parseFindings(audit);
    const ids = findings.map(({id}) => id);

    assert.equal(findings.length, 68);
    assert.equal(new Set(ids).size, 68);
    assert.deepEqual(new Set(findings.map(({tableShape}) => tableShape)), new Set(['finding-impact-action', 'finding-action']));
});

test('rejects severities outside the audited High and Medium scale', () => {
    const invalidAudit = audit.replace('| DDD-001 | High |', '| DDD-001 | Low |');

    assert.throws(() => parseFindings(invalidAudit), /Invalid severity "Low" for DDD-001/);
});

test('rejects unsupported finding table shapes', () => {
    const unsupportedAudit = audit.replace(
        '| ID | Severity | Finding and evidence | Impact | Required action |',
        '| ID | Severity | Finding and evidence | Impact | Owner | Required action |',
    );

    assert.throws(() => parseFindings(unsupportedAudit), /Unsupported finding table headers/);
});

test('requires one explicit planning metadata record for every finding', () => {
    const findings = parseFindings(audit);

    assert.doesNotThrow(() => validatePlanningMetadata(findings, planningMetadata));
    assert.deepEqual(
        [...Object.keys(planningMetadata)].sort(),
        findings.map(({id}) => id).sort(),
    );
});

test('rejects dependencies on later phases and dependency cycles', () => {
    const findings = parseFindings(audit);
    const laterPhaseDependency = {
        ...planningMetadata,
        'DDD-001': {...planningMetadata['DDD-001'], dependencies: ['ARCH-001']},
    };
    const cycle = {
        ...planningMetadata,
        'DDD-001': {...planningMetadata['DDD-001'], dependencies: ['DSL-001']},
    };

    assert.throws(
        () => validatePlanningMetadata(findings, laterPhaseDependency),
        /cannot depend on later-phase ARCH-001/,
    );
    assert.throws(() => validatePlanningMetadata(findings, cycle), /Dependency cycle detected/);
});

test('builds one complete GitHub task per finding', () => {
    const backlog = buildBacklog(audit);
    const tasks = backlog.split(/^### (?=(?:DDD|DSL|ARCH|REL|SCALE|OPS|SEC|DOC|QA)-\d{3}:)/m).slice(1);
    const taskCheckboxes = backlog.match(/^- \[ \] Task status$/gm) ?? [];
    const requiredLabels = [
        '**Severity:**',
        '**Effort:**',
        '**Phase:**',
        '**Dependencies:**',
        '**Problem**',
        '**Impact**',
        '**Implementation scope**',
        '**Technical guidance**',
        '**Acceptance criteria**',
        '**Required tests**',
        '**Documentation and compatibility**',
        '**Source audit:**',
    ];

    assert.equal(tasks.length, 68);
    assert.equal(taskCheckboxes.length, 68);
    assert.doesNotMatch(backlog, /^### \[ \] /m);
    assert.match(backlog, /## Release Policy/);
    assert.match(backlog, /## Closure Policy/);
    assert.match(backlog, /## Program Sizing/);
    assert.match(backlog, /## Technical Domain Index/);
    assert.doesNotMatch(backlog, /Phase 0/);
    assert.match(backlog, /36-99 engineer-months/);
    assert.match(backlog, /24-48 engineer-months/);
    assert.match(backlog, /\[Verification Record\]\(\.\/2026-07-16-nexus-independent-audit\.md#verification-record\)/);

    const headingIds = [...backlog.matchAll(/^### ((?:DDD|DSL|ARCH|REL|SCALE|OPS|SEC|DOC|QA)-\d{3}):/gm)].map((match) => match[1]);
    const position = new Map(headingIds.map((id, index) => [id, index]));
    for (const [id, plan] of Object.entries(planningMetadata)) {
        for (const dependency of plan.dependencies) {
            if (planningMetadata[dependency].phase === plan.phase) {
                assert.ok(position.get(dependency) < position.get(id), `${dependency} must be emitted before ${id}`);
            }
        }
    }
    for (const task of tasks) {
        for (const label of requiredLabels) {
            assert.match(task, new RegExp(label.replaceAll('*', '\\*')));
        }
    }
});

test('keeps the generated backlog byte-for-byte current', async () => {
    assert.equal(await readFile(backlogPath, 'utf8'), buildBacklog(audit));
});

test('builds print-oriented HTML with a cover and finding records', () => {
    const html = buildPrintHtml(audit);

    assert.match(html, /<!doctype html>/i);
    assert.match(html, /class="cover"/);
    assert.match(html, /@page/);
    assert.match(html, /counter\(page\)/);
    assert.match(html, /<section class="report-content">[\s\S]*<h1 id="nexus-independent-product-and-engineering-audit">/);
    assert.match(html, /\.report-content \{ page: report; \}/);
    assert.match(html, /@page \{\s*size: A4 portrait;\s*margin: 0;\s*background: white;\s*\}/);
    assert.match(html, /@page report \{\s*size: A4 portrait;\s*margin: 16mm 17mm 18mm;/);
    assert.match(html, /\.cover \{ width: 210mm; min-height: 297mm; margin: 0; padding: 30mm 22mm 24mm; transform: translate\(20mm, 28\.5mm\); transform-origin: top left; \}/);
    assert.doesNotMatch(html, /@page cover \{[^}]*background/);
    assert.match(html, /<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:">/);
    assert.doesNotMatch(html, /h2 \{ break-before: page; \}/);
    assert.match(html, /#consolidated-findings,[\s\S]*#prioritized-remediation-roadmap,[\s\S]*#final-feasibility-assessment,[\s\S]*#evidence-appendix \{ break-before: page; \}/);
    assert.match(html, /<h2 id="executive-verdict">Executive Verdict<\/h2>/);
    assert.match(html, /class="finding-record/);
    assert.doesNotMatch(html, /<table>[\s\S]*?<th>ID<\/th>[\s\S]*?<th>Severity<\/th>[\s\S]*?<th>Finding/);
});
