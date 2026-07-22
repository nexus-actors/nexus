# Audit Stack Merge Plan (#79–#105)

> Prerequisite for the ClusterNode actorization series — see
> `2026-07-22-clusternode-actorization-roadmap.md`. Execute top to bottom;
> stop on any unexpected output.

**Topology (verified 2026-07-22):** perfect stacked chain — #79 is based on
`main`, each PR is based on its predecessor's branch, and every PR head is an
ancestor of #105's head (`fix/audit-rel-009-delivery-admission`). All checks
green as of the last CI sweep.

**Strategy: retarget the chain tip (#105) to `main` and land it with a MERGE
COMMIT.** One operation puts the exact CI-tested tree on `main`, preserves all
per-finding commits (one commit per audit finding — full traceability), and
GitHub auto-marks #79–#104 as Merged because their tips become reachable from
`main`.

Explicitly rejected:
- `--squash` — collapses 26 findings into one commit, destroys per-finding
  history, and does NOT auto-close the lower PRs. (A squash-merge is also how
  the 16-node soak harness was lost from `feat/cluster-tcp` — same trap.)
- `--rebase` — rewrites SHAs, so original tips never reach `main` and none of
  #79–#104 auto-close; 26 manual closes with weaker provenance.
- Bottom-up sequential merging — 26 merge cycles, each waiting for required
  checks to re-run after GitHub retargets the next PR; a full day of CI for
  the same resulting tree.

**Rollback:** the single merge commit is revertible in one step:
`git revert -m 1 <merge-sha>`.

## Phase A — Preflight

- [ ] **A1. All PRs still green and unreviewed-blockers absent**

```bash
gh pr list --state open --limit 40 \
  --json number,mergeStateStatus,reviewDecision \
  --jq 'sort_by(.number)[] | "\(.number) \(.mergeStateStatus) \(.reviewDecision)"'
```
Expected: #79–#104 report `BLOCKED`/`BEHIND` or similar (they are stacked —
irrelevant, they will not be merged directly); **#105 must be the healthy
one** once retargeted (A3/B1). No PR should show failing checks:
`gh pr checks 105` → all pass.

- [ ] **A2. Chain integrity (re-verify at execution time)**

```bash
git fetch origin --prune
for pr in $(gh pr list --state open --limit 40 --json number --jq '.[].number' | sort -n); do
  head=$(gh pr view "$pr" --json headRefName --jq .headRefName)
  git merge-base --is-ancestor "origin/$head" origin/fix/audit-rel-009-delivery-admission \
    && echo "$pr OK" || echo "$pr NOT-IN-CHAIN ($head)"
done
```
Expected: every line `OK`. Any `NOT-IN-CHAIN` → STOP; that PR diverged and
needs its own merge after investigation.

- [ ] **A3. Chain root sits on current main (no rebase needed)**

```bash
test "$(git merge-base origin/main origin/fix/audit-rel-009-delivery-admission)" \
   = "$(git rev-parse origin/main)" && echo ROOT-ON-MAIN || echo NEEDS-REBASE
```
Expected: `ROOT-ON-MAIN`. If `NEEDS-REBASE`: `main` advanced since the last
cascade — rebase the chain tip onto `origin/main` first (expect clean; the
stack was fully rebased 2026-07-22), force-push via HTTPS, let CI re-run,
then restart at A1.

## Phase B — Land the stack

- [ ] **B1. Retarget #105 to main**

```bash
gh pr edit 105 --base main
gh pr view 105 --json baseRefName,mergeable,mergeStateStatus
```
Expected: `baseRefName: main`, `mergeable: MERGEABLE`. Checks stay satisfied
(they are recorded against the same head SHA).

- [ ] **B2. Merge with a merge commit (NOT squash, NOT rebase)**

```bash
gh pr merge 105 --merge \
  --subject "fix: land audit remediation stack #79-#105 (SEC/REL/OPS/DSL/DOC/QA/ARCH)"
```
Expected: merged; `main` now contains the 30 per-finding commits + 1 merge
commit, tree byte-identical to the CI-green #105 head.

- [ ] **B3. Confirm auto-close of the chain**

```bash
gh pr list --state open --limit 40 --json number --jq '.[].number'
```
Expected: none of #79–#105 listed. Any PR still open →
`gh pr view <n> --json state`; if not `MERGED`, close manually with
provenance:
`gh pr close <n> --comment "Landed on main via #105 merge commit $(git rev-parse origin/main)"`.

- [ ] **B4. main CI green**

```bash
gh run list --branch main --limit 3
gh run watch $(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId')
```
Expected: the push-triggered `ci.yml` run passes (same tree CI already
validated, so failure here means environment, not code).

## Phase C — Cleanup + refactor branch rebase

- [ ] **C1. Delete the 27 remote audit branches**

```bash
git fetch origin --prune
for b in $(git branch -r --list 'origin/fix/audit-*' --format '%(refname:short)' | sed 's#^origin/##'); do
  git push origin --delete "$b"
done
```
Expected: all `fix/audit-*` remote branches gone (safe: B3 confirmed merged).

- [ ] **C2. Rebase the refactor branch onto main**

```bash
git checkout refactor/cluster-node-actorization
git rebase origin/main
git log --oneline origin/main..HEAD
```
Expected: rebase drops the 30 already-merged commits automatically; only the
refactor's own docs commits remain ahead of `main`.

- [ ] **C3. Delete stale local branches (incl. absorbed SEC-008)**

```bash
git branch -D fix/audit-sec-008-node-identity
git branch --list 'fix/audit-*' --format '%(refname:short)' | xargs -r git branch -D
```
Expected: only active branches remain. SEC-008 has no PR and is absorbed into
refactor Plan 3 (design decision D4).

- [ ] **C4. Update the audit program tracker** — mark #79–#105 merged in the
  remediation backlog doc if it tracks status, and note SEC-008 is delivered
  via the actorization series.

Exit criterion: `main` green with the full stack landed, zero open audit PRs,
`refactor/cluster-node-actorization` rebased clean — Plan 1 execution may
begin.
