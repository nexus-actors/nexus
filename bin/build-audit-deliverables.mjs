#!/usr/bin/env node

import {mkdir, readFile, writeFile} from 'node:fs/promises';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

import {marked} from '../website/node_modules/marked/lib/marked.esm.js';

const scriptPath = fileURLToPath(import.meta.url);
const repoRoot = resolve(dirname(scriptPath), '..');
const auditPath = resolve(repoRoot, 'docs/audits/2026-07-16-nexus-independent-audit.md');
const backlogPath = resolve(repoRoot, 'docs/audits/2026-07-16-nexus-remediation-backlog.md');
const expectedFindingCount = 68;
const sourceAuditLink = './2026-07-16-nexus-independent-audit.md#consolidated-findings';

const task = (title, effort, phase, dependencies, testFocus, impact = undefined, technicalGuidance = undefined) => ({
    title,
    effort,
    phase,
    dependencies,
    testFocus,
    impact,
    technicalGuidance: technicalGuidance ?? `Design the implementation around this finding-specific verification contract: ${testFocus}`,
});

export const planningMetadata = {
    'DDD-001': task('Interpret every persistence effect hook', 'L', 'Phase 1 - Core correctness and security', [], 'Matrix-test None, Persist, Unhandled, reply, run, and stop hooks in event-sourced and durable-state interpreters.'),
    'DDD-002': task('Make saga effects crash-recoverable', 'XL', 'Phase 3 - Durable DDD and persistence', ['DDD-001', 'REL-010'], 'Crash at each commit/dispatch boundary and prove pending work is replayed exactly as specified without duplicate external effects.'),
    'DDD-003': task('Fence persistent aggregate writers', 'XL', 'Phase 3 - Durable DDD and persistence', [], 'Run concurrent stale/current writers against every store adapter and prove epochs reject the stale owner after lease turnover.'),
    'DDD-004': task('Deduplicate wallet commands', 'L', 'Phase 3 - Durable DDD and persistence', ['DDD-003'], 'Retry identical deposit and withdrawal command IDs before reply, after timeout, and after restart; assert one balance change and stable reply.'),
    'DDD-005': task('Version and upcast event schemas', 'XL', 'Phase 3 - Durable DDD and persistence', [], 'Recover compatibility fixtures for every supported historical schema, class rename, unknown type, and failed upcast.'),
    'DSL-001': task('Route persistent unhandled commands correctly', 'M', 'Phase 1 - Core correctness and security', ['DDD-001'], 'Send unsupported commands through event-sourced and durable-state behaviors and assert the documented dead-letter or explicit unhandled outcome.'),
    'DSL-002': task('Resolve the HTTP actor-handler contract', 'M', 'Phase 1 - Core correctness and security', [], 'Compile and invoke every documented handler form, including invalid actor shorthand and the supported FromActor path.'),
    'DSL-003': task('Make HTTP compilation terminal and deterministic', 'M', 'Phase 1 - Core correctness and security', [], 'Compile twice in global and worker-local actor modes and verify either identity-safe idempotence or a deterministic terminal-state exception.'),
    'DSL-004': task('Standardize persistence handler signatures', 'L', 'Phase 1 - Core correctness and security', ['DDD-001'], 'Execute documented event-sourced, durable-state, and Doctrine handler examples with reflection/static-analysis checks for one argument order.'),
    'DSL-005': task('Define a real recovery lifecycle', 'L', 'Phase 3 - Durable DDD and persistence', ['DDD-002'], 'Cover synchronous or asynchronous recovery completion, command arrival during recovery, bounded stashing, failure, and timeout.'),
    'DSL-006': task('Reject or apply routes added after compile', 'S', 'Phase 1 - Core correctness and security', ['DSL-003'], 'Register routes before and after compile and assert no route can be silently ignored.'),
    'DSL-007': task('Return typed application root handles', 'L', 'Phase 2 - Lifecycle and delivery', [], 'Start an app with multiple roots and verify callers can retrieve typed handles, dependency ordering, and shutdown ownership.'),
    'DSL-008': task('Replace public contract assertions', 'M', 'Phase 1 - Core correctness and security', [], 'Run invalid Props and reply construction with zend.assertions=-1 and assert stable public exceptions at the call boundary.'),
    'DSL-009': task('Type-check FromActor parameters at compile time', 'S', 'Phase 1 - Core correctness and security', [], 'Compile valid ActorRef parameters plus scalar, union, nullable, and incompatible object parameters; reject invalid handlers before serving.'),
    'DSL-010': task('Make protocol typing claims reproducible', 'M', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['QA-005'], 'Run the Psalm plugin over quick-start and ask/reply fixtures, including wrong command and wrong reply negative cases.'),
    'ARCH-001': task('Make split packages stable-installable', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Tag a release candidate, split every package, and install each plus the meta-package in stable-only clean Composer projects.'),
    'ARCH-002': task('Enforce runtime-to-core dependency direction', 'M', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Run Deptrac and a Composer-import consistency check with an intentional Runtime-to-Core violation fixture.'),
    'ARCH-003': task('Decouple Doctrine adapters from HTTP', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Install DBAL/ORM actor adapters in a CLI-only fixture and verify no HTTP package is installed unless its integration package is requested.'),
    'REL-001': task('Make Swoole mailbox admission truthful', 'L', 'Phase 1 - Core correctness and security', [], 'Saturate each Swoole mailbox strategy at and beyond capacity; assert accepted, dropped, backpressured, and failed asks match actual pushes.'),
    'REL-002': task('Complete death watch and child cleanup', 'XL', 'Phase 2 - Lifecycle and delivery', [], 'Exercise nested normal/failure stops, Terminated delivery, parent deregistration, lookup removal, and actor-name reuse across runtimes.'),
    'REL-003': task('Implement advertised supervision strategies', 'XL', 'Phase 2 - Lifecycle and delivery', ['REL-002'], 'Cross-runtime contract tests for one-for-one, all-for-one, escalate, retry windows, exponential backoff timing, and sibling/parent effects.'),
    'REL-004': task('Make suspended actors resumable', 'L', 'Phase 2 - Lifecycle and delivery', ['REL-002'], 'Suspend with queued user traffic, deliver resume/stop system messages, and verify ordering without message loss on every runtime.'),
    'REL-005': task('Cancel the active Swoole repeating timer', 'M', 'Phase 1 - Core correctness and security', [], 'Cancel before and after the first repeat tick, then stop/restart the owner and assert no later tick fires.'),
    'REL-006': task('Guarantee leaf-first bounded shutdown', 'XL', 'Phase 2 - Lifecycle and delivery', ['REL-002'], 'Build deep actor trees with slow children and verify PostStop order, one shared deadline, timeout reporting, and no resource use after parent closure.'),
    'REL-007': task('Define Messenger acknowledgement semantics', 'XL', 'Phase 3 - Durable DDD and persistence', ['REL-010'], 'Crash before enqueue, after enqueue, during handling, after persistence, and before broker ack; verify redelivery/loss matches the selected mode.'),
    'REL-008': task('Bound responder-side pending asks', 'M', 'Phase 2 - Lifecycle and delivery', [], 'Flood pending asks above the configured limit and assert deterministic shedding, cleanup on timeout/disconnect, and accurate gauges.'),
    'REL-009': task('Expose TCP delivery admission outcomes', 'XL', 'Phase 2 - Lifecycle and delivery', [], 'Inject disconnects, full buffers, partial writes, no-route, reconnect, and peer loss; correlate outcomes with sent/buffered/dropped metrics.'),
    'SCALE-001': task('Add worker transport admission and liveness', 'XL', 'Phase 4 - Scaling and operations', ['REL-009'], 'Kill, stall, remove, and saturate workers while routing; verify leases expire, queues stay bounded, sends fail visibly, and recovery does not black-hole keys.'),
    'SCALE-002': task('Specify and benchmark thread serialization', 'L', 'Phase 4 - Scaling and operations', ['SCALE-001'], 'Round-trip supported payload classes and reject resources/closures; benchmark latency, throughput, CPU, and memory by payload size.'),
    'SCALE-003': task('Stream recovery within explicit budgets', 'XL', 'Phase 3 - Durable DDD and persistence', ['DDD-005'], 'Recover large streams with and without snapshots under slow-store, timeout, corrupt-event, and memory-budget scenarios.'),
    'REL-010': task('Persist post-commit effects', 'XL', 'Phase 3 - Durable DDD and persistence', ['DDD-001'], 'Crash after state commit at every reply/command dispatch boundary and prove outbox/inbox replay plus consumer idempotency.'),
    'OPS-001': task('Bound and unify dead-letter handling', 'L', 'Phase 2 - Lifecycle and delivery', ['REL-001'], 'Drive closed, missing, full, and stopped destinations; assert bounded samples, monotonic counters, emitted events, and stable memory.'),
    'OPS-002': task('Implement snapshot retention semantics', 'L', 'Phase 3 - Durable DDD and persistence', ['SCALE-003'], 'Across every persistence adapter, create multiple snapshots/events and verify keepSnapshots, deletion boundaries, failed cleanup, and recoverability.'),
    'OPS-003': task('Reproduce and control Swoole worker cycling', 'L', 'Phase 4 - Scaling and operations', ['REL-006'], 'Run long low-traffic and restart soak tests with WebSockets and actor state; capture WorkerStop, max_wait_time, memory, and shutdown deadlines.'),
    'OPS-004': task('Separate membership floors from write safety', 'XL', 'Phase 4 - Scaling and operations', ['DDD-003'], 'Partition a multi-host cluster asymmetrically and prove stateful writes require external quorum/lease fencing rather than minimumMembers.'),
    'SCALE-004': task('Publish multi-host cluster limits', 'XL', 'Phase 4 - Scaling and operations', ['REL-009', 'SEC-007'], 'Measure 16/32/50+ nodes across hosts with TLS, churn, packet loss, partitions, slow peers, and representative payloads.'),
    'OPS-005': task('Restore reproducible performance harnesses', 'L', 'Phase 4 - Scaling and operations', ['SCALE-002', 'SCALE-004'], 'Run benchmark smoke tests from a clean checkout and reproduce published throughput from checked-in commands, fixtures, and raw results.'),
    'SEC-001': task('Authorize WebSockets before upgrade', 'XL', 'Phase 1 - Core correctness and security', [], 'Real-Swoole tests for missing, invalid, expired, and scoped tokens; assert rejection before 101 and shared principal resolution after upgrade.'),
    'SEC-002': task('Bound and passivate WebSocket channel actors', 'L', 'Phase 1 - Core correctness and security', ['SEC-001'], 'Churn unique authenticated/unauthenticated channel keys; assert caps, TTL/LRU eviction, last-close stop, bounded mailboxes, and stable memory.'),
    'SEC-003': task('Make annotated HTTP authorization fail closed', 'L', 'Phase 1 - Core correctness and security', [], 'Compile annotated routes with missing/misordered middleware and assert startup failure; test anonymous, wrong-scope, and valid-scope requests.'),
    'SEC-004': task('Replace default native object deserialization', 'XL', 'Phase 1 - Core correctness and security', [], 'Recover registered/unregistered and nested payload types; inject gadget-like/tampered rows and prove no constructor or magic method executes.'),
    'SEC-005': task('Sanitize pooled database connections', 'L', 'Phase 1 - Core correctness and security', [], 'Sequential tenant tests cover open transactions, roles, search_path/session settings, locks, cleanup failure, and poisoned-connection eviction.'),
    'SEC-006': task('Enforce JWT issuer and audience constraints', 'M', 'Phase 1 - Core correctness and security', [], 'Reject validly signed tokens with wrong/missing issuer, audience, subject, or time skew; accept only configured claims.'),
    'SEC-007': task('Secure cluster production defaults', 'L', 'Phase 1 - Core correctness and security', [], 'Reject non-loopback insecure production binds; handshake tests cover trusted/untrusted certificates, HMAC, downgrade attempts, and explicit dev override.'),
    'SEC-008': task('Authorize cluster node identities and control', 'XL', 'Phase 2 - Lifecycle and delivery', ['SEC-007'], 'Use per-node credentials to test endpoint spoofing, forged leave/control events, exposed-actor access, rotation, and trust-domain separation.'),
    'SEC-009': task('Enforce request limits before allocation', 'L', 'Phase 1 - Core correctness and security', [], 'Real-Swoole concurrency tests cover oversized known/unknown/chunked bodies, GET bodies, slow clients, headers, and native rejection limits.'),
    'SEC-010': task('Prevent cross-site WebSocket and cookie attacks', 'L', 'Phase 1 - Core correctness and security', ['SEC-001'], 'Browser-level handshake tests cover exact allowed/disallowed/null Origins, cookie credentials, CSRF tokens, and SameSite behavior.'),
    'SEC-011': task('Separate public liveness from private readiness', 'M', 'Phase 1 - Core correctness and security', [], 'Fail dependencies with sensitive exception messages and verify public liveness stays opaque while authenticated readiness and logs retain controlled detail.'),
    'SEC-012': task('Authorize Messenger producer-to-target routes', 'L', 'Phase 2 - Lifecycle and delivery', [], 'Publish validly encoded messages from authorized and unauthorized identities to each target; verify ACL denial, provenance integrity, and capacity isolation.'),
    'SEC-013': task('Harden wallet example administration', 'M', 'Phase 1 - Core correctness and security', ['SEC-003'], 'Exercise admin endpoints without/with wrong/valid roles and verify production mode rejects demo secrets and publicly bound database ports.'),
    'SEC-014': task('Harden release supply-chain inputs', 'XL', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['ARCH-001'], 'Clean release rehearsal verifies locked dependencies, audits, action/tool SHA pins, checksums, least-privilege tokens, SBOM, and provenance.'),
    'SEC-015': task('Run security packages in primary CI', 'M', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['QA-004'], 'Introduce failing auth/toolkit negative tests and prove the required root CI job detects them.'),
    'DOC-001': task('Make the wallet guide executable and safe', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['DDD-003', 'DDD-004', 'SEC-013'], 'Execute the documented wallet requests and file map in single/multi-worker modes; assert payload names and stated durability constraints.', 'Adopters can copy a financially themed example that uses stale files, invalid payloads, and unsafe per-worker state.'),
    'DOC-002': task('Align event-sourcing documentation with code', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['DDD-001', 'DDD-003', 'DSL-004', 'DSL-005'], 'Compile and execute every event-sourcing page snippet against the final handler, effect, recovery, and writer-identity contracts.', 'The flagship persistence guide teaches APIs and guarantees that fail or do not exist.'),
    'DOC-003': task('Replace the saga guide with an executable model', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['DDD-002', 'DSL-004'], 'Run the complete saga through success, restart, commit-before-dispatch crash, duplicate delivery, and compensation scenarios.', 'The current guide can neither execute as written nor deliver its promised crash recovery.'),
    'DOC-004': task('Describe replay filtering without false repair claims', 'S', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Verify documentation examples against in-memory filtering and persisted storage state before and after recovery.', 'Operators may believe corrupt or conflicting stored events were permanently repaired when only one replay was filtered.'),
    'DOC-005': task('Publish one generated Swoole requirement', 'M', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Run installation fixtures at the minimum supported Swoole/PHP versions and fail CI when docs diverge from Composer constraints.', 'Conflicting version requirements cause failed installs and make the supported platform impossible to determine.'),
    'DOC-006': task('Fix documentation snippet bootstrapping', 'M', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Verifier self-tests cover snippets with/without open tags, full/partial modes, namespaces, strict_types, and expected-invalid fences.', 'A broken verifier gives false confidence and rejects valid tagged examples before analysis.'),
    'DOC-007': task('Make snippet verification a practical CI gate', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['DOC-006'], 'Measure a cached/batched full documentation run, inject a broken snippet, and prove required CI fails within the agreed budget.', 'Hundreds of uncached analyzer processes keep documentation correctness outside required CI.'),
    'DOC-008': task('Document every split package locally', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['ARCH-001'], 'For all 40 package directories, validate a README template with install, purpose, minimal usage, requirements, maturity, and compatibility links.', 'Seventeen split packages lack the local information consumers expect on package registries.'),
    'DOC-009': task('Generate release documentation from manifests', 'M', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['ARCH-001'], 'Compare documented package count and constraints with the split matrix/manifests in CI and rehearse the documented release commands.', 'Maintainers following the stale guide can publish an incomplete or unresolvable release.'),
    'DOC-010': task('Define a genuinely comprehensive test command', 'M', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Enumerate every PHPUnit suite/runtime integration and verify the aggregate command either runs each or explicitly reports an environmental skip.', 'Contributors can receive a green "all tests" result while major runtimes and integration suites never ran.'),
    'QA-001': task('Make risk-based coverage thresholds blocking', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['REL-002', 'DDD-001'], 'Delete or mutate covered core branches to prove package/risk thresholds fail CI, including central actor internals.', 'Coverage regressions in the highest-risk runtime paths do not currently block merges.'),
    'QA-002': task('Restore blocking mutation assurance', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['QA-001'], 'Seed known surviving mutants in core, persistence, and security packages and prove agreed per-package MSI gates fail CI.', 'Tests can exercise lines without detecting behavior regressions, while the mutation job is allowed to fail.'),
    'QA-003': task('Make performance smoke tests executable', 'L', 'Phase 4 - Scaling and operations', ['OPS-005'], 'Run a bounded clean-checkout performance smoke job and assert no removed symbols, invalid fixtures, or missing harness assets.', 'The committed suite cannot detect performance regressions or substantiate published capacity claims.'),
    'QA-004': task('Include auth and toolkit suites in required CI', 'M', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Add a deliberately failing HTTP auth/toolkit test and prove every required CI entry point reports it.', 'External-input security code can regress while the primary named suites remain green.'),
    'QA-005': task('Restore Psalm level 1 on an immutable commit', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', [], 'Run uncached Psalm on the committed candidate and add negative protocol fixtures that demonstrate the advertised generic checks.', 'The audit-time tree cannot substantiate its level-1 type-safety claim while static analysis reports errors.'),
    'QA-006': task('Cover first-party symbols in architecture checks', 'L', 'Phase 5 - Documentation, examples, packaging, and release assurance', ['ARCH-002'], 'Fail Deptrac on an uncovered first-party namespace and on imports absent from the consuming Composer manifest.', 'A zero-violation report can conceal more than one thousand unclassified symbols and package-direction mistakes.'),
};

function splitTableRow(line) {
    const source = line.trim();
    if (!source.startsWith('|')) {
        return null;
    }

    const inner = source.endsWith('|') ? source.slice(1, -1) : source.slice(1);
    const cells = [];
    let cell = '';
    let escaped = false;

    for (const character of inner) {
        if (character === '|' && !escaped) {
            cells.push(cell.trim());
            cell = '';
            continue;
        }

        cell += character;
        escaped = character === '\\' && !escaped;
        if (character !== '\\') {
            escaped = false;
        }
    }

    cells.push(cell.trim());
    return cells;
}

function tableShape(headers) {
    const normalized = headers.map((header) => header.toLowerCase().replaceAll('`', '').trim());
    if (normalized[0] !== 'id' || normalized[1] !== 'severity' || !normalized[2]?.startsWith('finding')) {
        return null;
    }

    if (normalized.length === 5 && normalized[4] === 'required action' && /impact/.test(normalized[3])) {
        return 'finding-impact-action';
    }

    if (normalized.length === 4 && normalized[3] === 'required action') {
        return 'finding-action';
    }

    throw new Error(`Unsupported finding table headers: ${headers.join(' | ')}`);
}

function isDelimiter(cells, expectedLength) {
    return cells?.length === expectedLength && cells.every((cell) => /^:?-{3,}:?$/.test(cell));
}

function discoverFindingTables(markdown) {
    const lines = markdown.split(/\r?\n/);
    const tables = [];

    for (let lineIndex = 0; lineIndex < lines.length; lineIndex += 1) {
        const headers = splitTableRow(lines[lineIndex]);
        if (!headers) {
            continue;
        }

        const shape = tableShape(headers);
        if (!shape) {
            continue;
        }

        const delimiter = splitTableRow(lines[lineIndex + 1] ?? '');
        if (!isDelimiter(delimiter, headers.length)) {
            throw new Error(`Malformed finding table delimiter at line ${lineIndex + 2}`);
        }

        const findings = [];
        let rowIndex = lineIndex + 2;
        while (rowIndex < lines.length && lines[rowIndex].trim().startsWith('|')) {
            const cells = splitTableRow(lines[rowIndex]);
            if (cells.length !== headers.length) {
                throw new Error(`Finding table row at line ${rowIndex + 1} has ${cells.length} columns; expected ${headers.length}`);
            }

            const [id, severity, finding] = cells;
            if (!/^(?:DDD|DSL|ARCH|REL|SCALE|OPS|SEC|DOC|QA)-\d{3}$/.test(id)) {
                throw new Error(`Invalid finding ID "${id}" at line ${rowIndex + 1}`);
            }
            if (!['High', 'Medium'].includes(severity)) {
                throw new Error(`Invalid severity "${severity}" for ${id}`);
            }

            findings.push({
                id,
                severity,
                finding,
                impact: shape === 'finding-impact-action' ? cells[3] : planningMetadata[id]?.impact,
                action: shape === 'finding-impact-action' ? cells[4] : cells[3],
                tableShape: shape,
                sourceLine: rowIndex + 1,
            });
            rowIndex += 1;
        }

        if (findings.length === 0) {
            throw new Error(`Finding table at line ${lineIndex + 1} contains no findings`);
        }

        tables.push({start: lineIndex, end: rowIndex, findings});
        lineIndex = rowIndex - 1;
    }

    return {lines, tables};
}

export function parseFindings(markdown) {
    const {tables} = discoverFindingTables(markdown);
    const findings = tables.flatMap(({findings: rows}) => rows);
    const ids = findings.map(({id}) => id);
    const uniqueIds = new Set(ids);

    if (findings.length !== expectedFindingCount) {
        throw new Error(`Expected exactly ${expectedFindingCount} findings, parsed ${findings.length}`);
    }
    if (uniqueIds.size !== findings.length) {
        const duplicates = [...uniqueIds].filter((id) => ids.indexOf(id) !== ids.lastIndexOf(id));
        throw new Error(`Duplicate finding IDs: ${duplicates.join(', ')}`);
    }

    return findings;
}

export function validatePlanningMetadata(findings, metadata) {
    const findingIds = findings.map(({id}) => id).sort();
    const metadataIds = Object.keys(metadata).sort();
    const missing = findingIds.filter((id) => !(id in metadata));
    const unexpected = metadataIds.filter((id) => !findingIds.includes(id));

    if (missing.length > 0 || unexpected.length > 0) {
        throw new Error(`Planning metadata mismatch. Missing: ${missing.join(', ') || 'none'}. Unexpected: ${unexpected.join(', ') || 'none'}.`);
    }

    const validEfforts = new Set(['S', 'M', 'L', 'XL']);
    for (const finding of findings) {
        const plan = metadata[finding.id];
        for (const field of ['title', 'effort', 'phase', 'testFocus', 'technicalGuidance']) {
            if (typeof plan[field] !== 'string' || plan[field].trim() === '') {
                throw new Error(`${finding.id} has invalid ${field} planning metadata`);
            }
        }
        if (!validEfforts.has(plan.effort)) {
            throw new Error(`${finding.id} has unsupported effort ${plan.effort}`);
        }
        if (!Array.isArray(plan.dependencies)) {
            throw new Error(`${finding.id} dependencies must be an array`);
        }
        for (const dependency of plan.dependencies) {
            if (!findingIds.includes(dependency)) {
                throw new Error(`${finding.id} depends on unknown finding ${dependency}`);
            }
            if (dependency === finding.id) {
                throw new Error(`${finding.id} cannot depend on itself`);
            }
            if (phaseOrder(metadata[dependency].phase) > phaseOrder(plan.phase)) {
                throw new Error(`${finding.id} cannot depend on later-phase ${dependency}`);
            }
        }
        if (!finding.impact) {
            throw new Error(`${finding.id} requires an explicit impact statement`);
        }
    }

    const visiting = new Set();
    const visited = new Set();
    const visit = (id, path) => {
        if (visiting.has(id)) {
            throw new Error(`Dependency cycle detected: ${[...path, id].join(' -> ')}`);
        }
        if (visited.has(id)) {
            return;
        }

        visiting.add(id);
        for (const dependency of metadata[id].dependencies) {
            visit(dependency, [...path, id]);
        }
        visiting.delete(id);
        visited.add(id);
    };

    for (const id of findingIds) {
        visit(id, []);
    }
}

function phaseOrder(phase) {
    return Number.parseInt(phase.match(/^Phase (\d+)/)?.[1] ?? '99', 10);
}

function groupBy(values, keyFor) {
    const groups = new Map();
    for (const value of values) {
        const key = keyFor(value);
        const group = groups.get(key) ?? [];
        group.push(value);
        groups.set(key, group);
    }

    return groups;
}

function orderFindingsForExecution(findings, metadata) {
    const phases = groupBy(findings, (finding) => metadata[finding.id].phase);
    const ordered = [];

    for (const [, phaseFindings] of [...phases].sort(([left], [right]) => phaseOrder(left) - phaseOrder(right))) {
        const ids = new Set(phaseFindings.map(({id}) => id));
        const pending = new Map(phaseFindings.map((finding) => [finding.id, finding]));

        while (pending.size > 0) {
            const ready = [...pending.values()]
                .filter(({id}) => metadata[id].dependencies.every((dependency) => !ids.has(dependency) || !pending.has(dependency)))
                .sort((left, right) => left.id.localeCompare(right.id));
            if (ready.length === 0) {
                throw new Error(`Dependency cycle detected while ordering ${phaseFindings[0].id}`);
            }
            for (const finding of ready) {
                ordered.push(finding);
                pending.delete(finding.id);
            }
        }
    }

    return ordered;
}

function rewriteAuditInternalLinks(value) {
    return value.replace(/\]\(#([^)]+)\)/g, `](${sourceAuditLink.slice(0, sourceAuditLink.indexOf('#'))}#$1)`);
}

function familyDocumentation(finding) {
    const family = finding.id.split('-')[0];
    const guidance = {
        DDD: 'Update persistence/DDD guarantees and add a migration note for stored streams or command protocols affected by the change.',
        DSL: 'Update API reference and runnable examples; document the compatibility behavior for callers using the old DSL contract.',
        ARCH: 'Update package READMEs, dependency diagrams, and release/install guidance; verify split-package compatibility.',
        REL: 'Publish the cross-runtime lifecycle or delivery contract and call out any changed failure outcome or default.',
        SCALE: 'Document measured capacity, supported payload/topology limits, overload behavior, and upgrade constraints.',
        OPS: 'Update operator runbooks, metrics/alerts, rollout and rollback procedures, and any changed retention/shutdown semantics.',
        SEC: 'Update the threat model and secure-configuration guide; provide an upgrade note for stricter defaults or rejected traffic.',
        DOC: 'Keep the page executable in CI and add an explicit maturity/compatibility note wherever implementation remains constrained.',
        QA: 'Update contributor and release-gate documentation so the new required check and local reproduction command are explicit.',
    };

    return guidance[family];
}

function technicalDomain(finding) {
    const domains = {
        DDD: 'Domain model and persistence',
        DSL: 'Public API and DSL',
        ARCH: 'Architecture and packaging',
        REL: 'Runtime reliability',
        SCALE: 'Scaling and transport',
        OPS: 'Operations and observability',
        SEC: 'Security',
        DOC: 'Documentation and developer experience',
        QA: 'Quality and release engineering',
    };

    return domains[finding.id.split('-')[0]];
}

function taskAnchor(id) {
    return id.toLowerCase();
}

export function buildBacklog(markdown) {
    const findings = parseFindings(markdown);
    validatePlanningMetadata(findings, planningMetadata);

    const ordered = orderFindingsForExecution(findings, planningMetadata);
    const counts = findings.reduce((result, finding) => ({
        ...result,
        [finding.severity]: (result[finding.severity] ?? 0) + 1,
    }), {});

    const output = [
        '# Nexus Remediation Backlog',
        '',
        '> Generated from the canonical independent audit by `bin/build-audit-deliverables.mjs`. Edit planning metadata in the generator and regenerate; do not hand-edit generated task bodies.',
        '',
        `**Scope:** ${findings.length} findings (${counts.High ?? 0} High, ${counts.Medium ?? 0} Medium, ${counts.Low ?? 0} Low)`,
        `**Source:** [Nexus Independent Product and Engineering Audit](${sourceAuditLink})`,
        '',
        '## Planning Model',
        '',
        '| Effort | Working estimate |',
        '|---|---|',
        '| S | <=3 days |',
        '| M | 4-10 days |',
        '| L | 2-5 weeks |',
        '| XL | 1-3 months; likely RFC and staged rollout |',
        '',
        'Effort is a planning band, not a commitment. Each task must be refined against the release candidate, assigned an accountable owner, and split into implementation issues when its acceptance criteria cannot fit one pull request.',
        '',
        '## Release Policy',
        '',
        'Every High finding must be closed, its affected feature or public claim must be removed, or a named accountable owner must approve a time-bounded risk acceptance with explicit deployment controls. Labeling the project or feature "experimental" is not sufficient risk acceptance.',
        '',
        '## Closure Policy',
        '',
        'A technical finding closes only when its implementation, required tests, and documentation or compatibility changes land together, or when the affected feature is deliberately removed. Documentation-only wording changes do not close technical findings. Documentation tasks validate the corrected implementation and its executable examples; they do not substitute for remediation.',
        '',
        '## Program Sizing',
        '',
        'A mechanical standalone sum of the task bands is approximately **36-99 engineer-months**. That sum assumes every issue is delivered independently, so it overstates work where one shared runtime, persistence, security, or release implementation closes several findings.',
        '',
        'The preliminary consolidated portfolio hypothesis is **24-48 engineer-months** after accounting for shared implementations and coordinated delivery. This range must be refined through the Phase 1-4 RFCs, dependency discovery, staffing model, and staged rollout plans. Effort bands are not additive commitments. This generated backlog is the authoritative sizing model and supersedes earlier rough estimates.',
        '',
        '## Task Index',
        '',
        '| Phase | Tasks |',
        '|---|---:|',
    ];

    const byPhase = groupBy(ordered, (finding) => planningMetadata[finding.id].phase);
    for (const [phase, phaseFindings] of byPhase) {
        output.push(`| ${phase} | ${phaseFindings.length} |`);
    }

    output.push('', '## Technical Domain Index', '', '| Technical domain | Findings | Task links |', '|---|---:|---|');
    const byDomain = groupBy(findings, technicalDomain);
    for (const [domain, domainFindings] of byDomain) {
        const links = domainFindings.map(({id}) => `[${id}](#${taskAnchor(id)})`).join(', ');
        output.push(`| ${domain} | ${domainFindings.length} | ${links} |`);
    }

    output.push('');

    for (const [phase, phaseFindings] of byPhase) {
        output.push(`## ${phase}`, '');
        for (const finding of phaseFindings) {
            const plan = planningMetadata[finding.id];
            const problem = rewriteAuditInternalLinks(finding.finding);
            const impact = rewriteAuditInternalLinks(finding.impact);
            const action = rewriteAuditInternalLinks(finding.action);
            const dependencies = plan.dependencies.length === 0
                ? 'None'
                : plan.dependencies.map((id) => `[${id}](#${taskAnchor(id)})`).join(', ');
            output.push(
                `<a id="${taskAnchor(finding.id)}"></a>`,
                '',
                `### ${finding.id}: ${plan.title}`,
                '',
                '- [ ] Task status',
                `- **Severity:** ${finding.severity}`,
                `- **Effort:** ${plan.effort}`,
                `- **Phase:** ${plan.phase}`,
                `- **Technical domain:** ${technicalDomain(finding)}`,
                `- **Dependencies:** ${dependencies}`,
                '',
                '**Problem**',
                '',
                problem,
                '',
                '**Impact**',
                '',
                impact,
                '',
                '**Implementation scope**',
                '',
                `- ${action}`,
                `- Keep the change scoped to the cited behavior and its adapters; remove or explicitly deprecate any public claim that cannot be implemented in this phase.`,
                '',
                '**Technical guidance**',
                '',
                `${plan.technicalGuidance} The concrete implementation contract is: ${action}`,
                '',
                '**Acceptance criteria**',
                '',
                `- [ ] The required action is implemented: ${action}`,
                `- [ ] The domain regression evidence passes: ${plan.testFocus}`,
                '- [ ] Failure and overload paths are observable, deterministic, and documented where the finding crosses a runtime or trust boundary.',
                '',
                '**Required tests**',
                '',
                `- ${plan.testFocus}`,
                '- Run the smallest affected package suites plus the repository-required static analysis and style gates.',
                '',
                '**Documentation and compatibility**',
                '',
                `- Document the implemented behavior, migration impact, and compatibility consequences of this finding-specific action: ${action}`,
                `- ${familyDocumentation(finding)}`,
                '',
                `**Source audit:** [${finding.id}](${sourceAuditLink}) (canonical table row near source line ${finding.sourceLine})`,
                '',
            );
        }
    }

    return `${output.join('\n')}\n`;
}

function escapeHtml(value) {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

function renderFindingRecords(findings) {
    return [
        '<section class="finding-records">',
        ...findings.map((finding) => {
            const plan = planningMetadata[finding.id];
            return [
                `<article class="finding-record severity-${finding.severity.toLowerCase()}">`,
                '<header class="finding-record__header">',
                `<span class="finding-id">${escapeHtml(finding.id)}</span>`,
                `<span class="severity-badge">${escapeHtml(finding.severity)}</span>`,
                '</header>',
                `<h4>${escapeHtml(plan.title)}</h4>`,
                '<div class="finding-record__body">',
                `<div><h5>Finding</h5>${marked.parse(finding.finding, {gfm: true})}</div>`,
                `<div><h5>Impact</h5>${marked.parse(finding.impact, {gfm: true})}</div>`,
                `<div class="required-action"><h5>Required action</h5>${marked.parse(finding.action, {gfm: true})}</div>`,
                '</div>',
                '</article>',
            ].join('\n');
        }),
        '</section>',
    ].join('\n');
}

function replaceFindingTables(markdown) {
    const {lines, tables} = discoverFindingTables(markdown);
    for (const table of [...tables].reverse()) {
        lines.splice(table.start, table.end - table.start, renderFindingRecords(table.findings));
    }
    return lines.join('\n');
}

function addHeadingIds(html) {
    const seen = new Map();

    return html.replace(/<h([1-3])>([\s\S]*?)<\/h\1>/g, (heading, level, content) => {
        const plainText = content
            .replace(/<[^>]+>/g, '')
            .replace(/&(?:#39|quot);/g, '')
            .replace(/&amp;/g, 'and')
            .toLowerCase();
        const baseSlug = plainText
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'section';
        const count = (seen.get(baseSlug) ?? 0) + 1;
        seen.set(baseSlug, count);
        const slug = count === 1 ? baseSlug : `${baseSlug}-${count}`;

        return `<h${level} id="${slug}">${content}</h${level}>`;
    });
}

export function buildPrintHtml(markdown) {
    const findings = parseFindings(markdown);
    validatePlanningMetadata(findings, planningMetadata);
    const counts = findings.reduce((result, finding) => ({
        ...result,
        [finding.severity]: (result[finding.severity] ?? 0) + 1,
    }), {});
    const transformed = replaceFindingTables(markdown);
    const content = addHeadingIds(marked.parse(transformed, {gfm: true}));

    return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nexus Independent Product and Engineering Audit</title>
<style>
:root {
    --ink: #17212b;
    --muted: #52606d;
    --line: #cbd5df;
    --paper: #ffffff;
    --panel: #f4f7f9;
    --brand: #0d5c63;
    --brand-dark: #083d42;
    --high: #a62929;
    --high-bg: #fbeaea;
    --medium: #8a5700;
    --medium-bg: #fff3d6;
    --low: #32633a;
    --low-bg: #eaf5eb;
}
* { box-sizing: border-box; }
html { font-family: Inter, "IBM Plex Sans", "Segoe UI", Arial, sans-serif; color: var(--ink); background: #e7ebef; font-size: 10.5pt; line-height: 1.5; }
body { margin: 0 auto; max-width: 210mm; background: var(--paper); }
main { padding: 17mm 17mm 20mm; }
.cover { page: cover; min-height: 255mm; margin: -17mm -17mm 18mm; padding: 28mm 22mm 22mm; color: white; background: var(--brand-dark); display: flex; flex-direction: column; justify-content: space-between; break-after: page; }
.cover__eyebrow { font-size: 9pt; text-transform: uppercase; font-weight: 700; letter-spacing: .08em; color: #b9e0df; }
.cover h1 { margin: 18mm 0 5mm; color: white; font-size: 34pt; line-height: 1.08; max-width: 155mm; }
.cover__subtitle { max-width: 145mm; margin: 0; color: #d8eceb; font-size: 15pt; line-height: 1.4; }
.cover__verdict { margin-top: 18mm; padding: 6mm 0; border-top: 1px solid #74aaa8; border-bottom: 1px solid #74aaa8; max-width: 150mm; font-size: 12pt; }
.cover__stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6mm; max-width: 135mm; }
.cover__stat strong { display: block; font-size: 24pt; color: white; }
.cover__stat span { color: #b9e0df; font-size: 9pt; text-transform: uppercase; }
.cover__meta { color: #b9e0df; font-size: 9pt; }
.report-content { page: report; }
h1, h2, h3, h4, h5 { color: var(--brand-dark); line-height: 1.2; break-after: avoid; }
h1 { font-size: 25pt; margin: 0 0 8mm; }
h2 { font-size: 18pt; margin: 12mm 0 4mm; padding-bottom: 2mm; border-bottom: 2px solid var(--brand); }
h3 { font-size: 14pt; margin: 8mm 0 3mm; }
h4 { font-size: 11.5pt; margin: 3mm 0; }
h5 { margin: 0 0 1mm; font-size: 8pt; text-transform: uppercase; color: var(--muted); }
p { margin: 0 0 3.2mm; }
ul, ol { margin: 0 0 4mm; padding-left: 6mm; }
li { margin-bottom: 1.2mm; }
a { color: var(--brand); text-decoration: none; }
code { font-family: "SFMono-Regular", Consolas, monospace; font-size: 8.7pt; background: #eef2f4; padding: .2mm .8mm; border-radius: 1mm; overflow-wrap: anywhere; }
pre { padding: 4mm; overflow: hidden; white-space: pre-wrap; overflow-wrap: anywhere; background: #18252d; color: #f4f7f9; border-radius: 1.5mm; break-inside: avoid; }
pre code { padding: 0; background: transparent; color: inherit; }
blockquote { margin: 4mm 0; padding: 3mm 5mm; border-left: 1.5mm solid var(--brand); background: var(--panel); color: var(--muted); }
table { width: 100%; margin: 4mm 0 6mm; border-collapse: collapse; table-layout: auto; font-size: 8.7pt; break-inside: auto; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
th { background: var(--brand-dark); color: white; text-align: left; font-weight: 700; }
th, td { padding: 2.2mm 2.5mm; border: .25mm solid var(--line); vertical-align: top; overflow-wrap: anywhere; }
tbody tr:nth-child(even) { background: var(--panel); }
.finding-records { margin: 4mm 0 7mm; }
.finding-record { margin: 0 0 4mm; padding: 4mm 4.5mm; border: .25mm solid var(--line); border-left: 1.5mm solid var(--muted); background: white; break-inside: avoid; }
.finding-record.severity-high { border-left-color: var(--high); }
.finding-record.severity-medium { border-left-color: var(--medium); }
.finding-record.severity-low { border-left-color: var(--low); }
.finding-record__header { display: flex; align-items: center; justify-content: space-between; gap: 4mm; }
.finding-id { font-family: "SFMono-Regular", Consolas, monospace; font-weight: 800; color: var(--brand-dark); font-size: 9.5pt; }
.severity-badge { padding: .8mm 2mm; border-radius: 1mm; font-size: 8pt; font-weight: 800; text-transform: uppercase; background: var(--panel); }
.severity-high .severity-badge { color: var(--high); background: var(--high-bg); }
.severity-medium .severity-badge { color: var(--medium); background: var(--medium-bg); }
.severity-low .severity-badge { color: var(--low); background: var(--low-bg); }
.finding-record__body { display: grid; grid-template-columns: 1.25fr .75fr; gap: 3mm 5mm; }
.finding-record__body p { margin-bottom: 1.5mm; }
.finding-record__body .required-action { grid-column: 1 / -1; padding-top: 2.5mm; border-top: .25mm solid var(--line); }
@page {
    size: A4 portrait;
    margin: 0;
    background: white;
}
@page report {
    size: A4 portrait;
    margin: 16mm 17mm 18mm;
    @top-left { content: "NEXUS INDEPENDENT AUDIT"; color: #52606d; font-size: 8pt; }
    @top-right { content: "16 JULY 2026"; color: #52606d; font-size: 8pt; }
    @bottom-left { content: "CONFIDENTIAL ENGINEERING REVIEW"; color: #52606d; font-size: 7.5pt; }
    @bottom-right { content: "PAGE " counter(page) " OF " counter(pages); color: #52606d; font-size: 8pt; }
}
@page cover { margin: 0; @top-left { content: none; } @top-right { content: none; } @bottom-left { content: none; } @bottom-right { content: none; } }
@media print {
    html, body { width: 210mm; max-width: none; background: white; }
    main { padding: 0; }
    .cover { width: 210mm; min-height: 297mm; margin: 0; padding: 30mm 22mm 24mm; transform: translate(20mm, 28.5mm); transform-origin: top left; }
    #consolidated-findings,
    #prioritized-remediation-roadmap,
    #final-feasibility-assessment,
    #evidence-appendix { break-before: page; }
    .report-content > h1 { break-before: auto; }
}
@media screen {
    body { box-shadow: 0 0 12mm rgba(23, 33, 43, .18); }
}
</style>
</head>
<body>
<main>
<section class="cover">
    <div>
        <div class="cover__eyebrow">Independent Product and Engineering Review</div>
        <h1>Nexus<br>Independent Audit</h1>
        <p class="cover__subtitle">Architecture, DDD, API design, runtime correctness, scaling, persistence, security, operations, packaging, documentation, examples, and product feasibility.</p>
        <p class="cover__verdict"><strong>Verdict:</strong> a substantial actor-system foundation that should remain experimental until its durable, lifecycle, delivery, and security release gates are closed.</p>
    </div>
    <div>
        <div class="cover__stats">
            <div class="cover__stat"><strong>${findings.length}</strong><span>Total findings</span></div>
            <div class="cover__stat"><strong>${counts.High ?? 0}</strong><span>High severity</span></div>
            <div class="cover__stat"><strong>${counts.Medium ?? 0}</strong><span>Medium severity</span></div>
        </div>
        <p class="cover__meta">Audit date: 16 July 2026<br>Semantic baseline: 8970faa2<br>Prepared for technical and executive review</p>
    </div>
</section>
<section class="report-content">
${content}
</section>
</main>
</body>
</html>
`;
}

async function loadAndValidateAudit() {
    const markdown = await readFile(auditPath, 'utf8');
    const findings = parseFindings(markdown);
    validatePlanningMetadata(findings, planningMetadata);
    return {markdown, findings};
}

async function runCli(arguments_) {
    const [mode, value, ...extra] = arguments_;
    if (extra.length > 0 || !['--validate', '--backlog', '--html'].includes(mode) || (mode === '--html') !== Boolean(value)) {
        throw new Error('Usage: build-audit-deliverables.mjs --validate | --backlog | --html <output-path>');
    }

    const {markdown, findings} = await loadAndValidateAudit();
    if (mode === '--validate') {
        const counts = groupBy(findings, ({severity}) => severity);
        process.stdout.write(`Validated ${findings.length} unique findings and ${Object.keys(planningMetadata).length} metadata records (${counts.get('High')?.length ?? 0} High, ${counts.get('Medium')?.length ?? 0} Medium, ${counts.get('Low')?.length ?? 0} Low).\n`);
        return;
    }

    const outputPath = mode === '--backlog' ? backlogPath : resolve(process.cwd(), value);
    const output = mode === '--backlog' ? buildBacklog(markdown) : buildPrintHtml(markdown);
    await mkdir(dirname(outputPath), {recursive: true});
    await writeFile(outputPath, output, 'utf8');
    process.stdout.write(`Wrote ${outputPath}\n`);
}

if (process.argv[1] && resolve(process.argv[1]) === scriptPath) {
    runCli(process.argv.slice(2)).catch((error) => {
        process.stderr.write(`${error.message}\n`);
        process.exitCode = 1;
    });
}
