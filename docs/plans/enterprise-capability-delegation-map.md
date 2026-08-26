# Enterprise-grade control delegation map

**Fixed point:** `c09af1a`
**Purpose:** let implementation agents deliver bounded, reviewable controls within the product's stated small/mid-sized operator-console boundary while the primary agent owns architecture, security review, integration, full-suite verification, release packaging, and push.

This is a decision map and implementation queue, not a promise that feature count changes the product into an enterprise GRC suite. Every delivered slice must update the capability ledger with narrow claims and explicit non-claims.

## Delivery model

Each implementation ticket is one isolated branch/worktree and one agent-sized session. An implementer owns the complete vertical slice: migration, model, service, authorization, REST, operator interface, factories, focused tests, operator/API documentation, and the matching `docs/IRM_CAPABILITY_EVIDENCE.md` row.

The primary agent does not complete unfinished implementation. It reviews the fixed-point diff, returns findings, and accepts only a clean resubmission. After acceptance, the primary agent integrates in dependency order, runs the relevant focused suite and one full suite, commits/pushes, and produces the immutable AWS-compatible release bundle. Production deployment remains separately authorized by `docs/DEPLOYMENT_AGENT.md`.

### Required implementer handoff

Every handoff must contain:

- branch name, fixed-point commit, and final commit;
- concise behavior and schema summary;
- changed-file list and migration rollback behavior;
- focused test command and exact result;
- exact-file Pint result and `git diff --check` result;
- authorization, privacy/redaction, lock-order, bounds, and fingerprint notes;
- documentation/ledger row changed;
- known limitations and anything deliberately left out.

### Primary-agent acceptance gates

1. Diff contains only the assigned slice and preserves existing user work.
2. Form Request and locked service authorization agree, including direct-service calls.
3. Lock order is compatible with every sibling mutation path.
4. Server-owned fields are prohibited and evidence fingerprints reconstruct after storage round-trip.
5. REST, Filament, exports, downloads, and factories obey the same ACL/redaction boundary.
6. Migrations are additive, resumable, MySQL-safe, and retain governed evidence on routine rollback.
7. Exact bounds, immutable guards, legacy boundaries, failure rollback, and factory parity are tested.
8. Product docs and evidence claims describe only what the executable tests establish.

## Ticket 1 — Establish the post-Slice-94 completion boundary

- **Blocked by:** —
- **Type:** Research
- **Question:** Which remaining compliance-case controls materially improve governance depth within the stated product boundary without duplicating capabilities already delivered in Slices 82–94?
- **Answer:** Build the eight slices below. They close conflict independence, need-to-know access, time-based oversight, communications decisions, retention, reopening, archive integrity, and portfolio oversight. Do not add AI inference, legal conclusions, external delivery, or automated deletion.

## Ticket 2 / Slice 95 — Conflict, recusal, and independence register

- **Blocked by:** Ticket 1
- **Type:** Prototype
- **Question:** Can the product retain attributable conflict declarations and recusals and enforce them across assignment, investigation, review, resolution, and closure?
- **Answer / acceptance contract:**
  - Add immutable declarations and terminal manager decisions bound to the exact case/event and actor identities.
  - A confirmed conflict removes the actor from governed assignment/review/closure paths; historical evidence remains readable.
  - Lock the case before declaration/decision and before every downstream conflict check.
  - Provide scoped REST/operator history, canonical factories, exact bounds, immutability, and separation tests.
  - Explicitly disclaim automated conflict discovery, legal/ethical determination, and organizational-independence assurance.

## Ticket 3 / Slice 96 — Need-to-know case access grants

- **Blocked by:** Slice 95
- **Type:** Prototype
- **Question:** Can sensitive case access be delegated and revoked without relying only on broad global permissions?
- **Answer / acceptance contract:**
  - Add immutable, attributable, time-bounded case access grants with revocation evidence and purpose.
  - Exact active grants may confer case read access only; mutation permissions remain separately governed.
  - Revocation immediately removes current API/operator discovery while retaining historical grant evidence.
  - Existing reporter, investigator, manager, verifier, and closure-package boundaries must remain intact.
  - Test expiry boundaries, revocation, soft-deleted users, direct-service authorization, safe pagination, and no cross-case disclosure.

## Ticket 4 / Slice 97 — Investigation milestone and overdue register

- **Blocked by:** Ticket 1
- **Type:** Prototype
- **Question:** Can managers define deliberate case milestones and retain idempotent due/overdue evidence without claiming legal deadline calculation?
- **Answer / acceptance contract:**
  - Retain bounded milestones with owner, UTC due time, description, exact case/event context, and fingerprint.
  - Append immutable completion and scheduled due-soon/overdue evidence under a single canonical lock order.
  - Database notifications and delivery ledgers must be atomic, idempotent, and accurately described as in-app insertion only.
  - Closed cases reject new milestones; closure requires required milestones terminal or explicitly waived by a separated manager.
  - Cover exact-time boundaries, retries/concurrency, cancellation rollback, notification deletion, and safe REST/operator history.

## Ticket 5 / Slice 98 — Governed communication-decision register

- **Blocked by:** Slice 96
- **Type:** Prototype
- **Question:** Can the case retain who decided that an internal or external communication was required, prepared, sent, waived, or cancelled without pretending to transmit it?
- **Answer / acceptance contract:**
  - Add bounded immutable decision/event history with audience, purpose, deliberate deadline, unverified external reference, exact case/event context, actor/time, and fingerprint.
  - Sent requires a nonblank unverified reference; terminal decisions are immutable.
  - Case access and need-to-know grants govern discovery; sensitive audiences receive safe projections only if an authenticated recipient workflow is deliberately included.
  - No email, regulator filing, legal-deadline engine, delivery proof, or content-truth claim.

## Ticket 6 / Slice 99 — Retention classification and disposition review

- **Blocked by:** Ticket 1
- **Type:** Prototype
- **Question:** Can a closed case receive an attributable retention classification and independent disposition decision without deleting governed evidence?
- **Answer / acceptance contract:**
  - Retain policy/reference, classification, start/end dates, rationale, exact closure package/review context, classifier, and fingerprint.
  - A separated reviewer may approve, reject, or defer a disposition proposal only after legal holds are released.
  - Approval records permission to proceed; it must not automatically delete files or rows.
  - Test hold precedence, stale package/review rejection, UTC date boundaries, separation, immutable versions, and retained rollback.

## Ticket 7 / Slice 100 — Independently governed case reopening

- **Blocked by:** Slices 95 and 99
- **Type:** Prototype
- **Question:** Can a terminal case be reopened without rewriting the original closure, package, review, or retention evidence?
- **Answer / acceptance contract:**
  - Only a separated authorized manager may propose and another may approve a reopen decision against the exact terminal case, latest approved package, and retention context.
  - Approval starts a new linked lifecycle cycle and appends a new event; it never edits prior terminal evidence.
  - Rejection is retained and permits a later proposal; exact bounds and latest-only review apply.
  - Reopen is blocked by an executed external disposition marker if such a marker does not exist as governed evidence; do not infer external state.

## Ticket 8 / Slice 101 — Closure archive manifest

- **Blocked by:** Slices 98–100
- **Type:** Prototype
- **Question:** Can the product create a bounded, independently reviewed archive manifest that proves which governed records and retained bytes were packaged?
- **Answer / acceptance contract:**
  - Generate a private immutable archive from the exact approved closure package plus all current governed registries.
  - Retain ordered source fingerprints, per-file size/SHA-256, archive size/hash, generator/time, and schema version.
  - Require independent approval before exact download; downloads reauthorize current case access and verify bytes.
  - Use compensating cleanup language for storage failures; do not claim atomic filesystem rollback, authenticity, legal admissibility, or external delivery.

## Ticket 9 / Slice 102 — Compliance-case portfolio oversight

- **Blocked by:** Slices 95–101
- **Type:** Prototype
- **Question:** Can authorized leaders inspect bounded aggregate workflow health without exposing allegation or evidence content?
- **Answer / acceptance contract:**
  - Derive counts and durations only from governed timestamps/events: intake status, age bands, phase, overdue milestones, open holds/issues, closure/reopen state, and review outcomes.
  - Enforce the caller's case visibility before aggregation so totals do not leak hidden-case existence.
  - Provide bounded date filters and CSV/private export parity with semantic labels and eager/batched queries.
  - Test mixed-ACL populations, zero-result privacy, UTC boundaries, and query bounds.
  - Explicitly disclaim misconduct trends, allegation truth, legal exposure, investigator performance, risk prediction, and effectiveness inference.

## Parallel execution waves

| Wave | Implementer A | Implementer B | Implementer C | Integration order |
|---|---|---|---|---|
| A | Slice 95 | Slice 97 | Slice 99 | 95 → 97 → 99 |
| B | Slice 96 | — | — | 96 after 95 |
| C | Slice 98 | Slice 100 | — | 98 → 100 after 96/99 |
| D | Slice 101 | — | — | after 98–100 |
| E | Slice 102 | — | — | last |

Parallel agents must not share a working tree. Wave branches start from the named fixed point; after each accepted integration, dependent work rebases onto the new branch head. If two slices touch the capability ledger or common case policy, the later integration rebases and resolves that narrow conflict before review.

## Review prompt for every implementation agent

> Implement only the assigned slice from `docs/plans/enterprise-capability-delegation-map.md`. Start from the stated fixed point in an isolated worktree. Follow `CONTRIBUTING.md`, `CONTEXT.md`, `design.md`, and `docs/DEPLOYMENT_AGENT.md`. Deliver a complete vertical slice with TDD, additive retained migration, exact authorization/privacy/lock-order/fingerprint behavior, focused PHPUnit evidence, exact-file Pint, API/operator docs, and one narrow capability-ledger row. Do not deploy, push to production, change infrastructure, or broaden claims. Return the required implementer handoff and stop for primary-agent review.

## Completion decision

After Slice 102, run a fresh repository-wide capability audit rather than automatically inventing Slice 103. The audit must classify remaining gaps as:

1. a missing enterprise control needed by the stated product boundary;
2. integration hardening or cross-module parity;
3. operational/deployment readiness requiring external authorization; or
4. an intentionally absent capability whose limitation is already disclosed.

Only categories 1 and 2 become new implementation slices.
