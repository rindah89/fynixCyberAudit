# Governance issue and remediation lifecycle

Fynix provides one governed lifecycle for issues opened by risk portfolio reviews, third-party risk reviews, missed third-party collaboration escalation targets, AI monitoring reviews, operational-resilience exercises, continuous control tests, and policy-exception monitoring reviews. The lifecycle connects these source records to the existing remediation and accepted audit-evidence workflows without claiming automatic remediation execution, evidence authenticity, or effectiveness telemetry.

## Registration and ownership

Each new source issue is registered as `open` in the same database transaction that creates it. Registration records the source issue, its accountable owner, the actor who triggered the source workflow, and an append-only transition through product interfaces. Existing issues are backfilled during migration; because the original opener was not stored, the migration explicitly attributes the initial transition to the recorded issue owner as a proxy. Any legacy non-open status is normalized to `open` because the historical data cannot prove the new independent-verification closure invariants; the migration records that normalization in its initial rationale.

Issue owners can read their lifecycle and transition history. Users with `Manage Issue Lifecycle` can create remediation handoffs, request verification, and reopen closed issues. Users with `Verify Issue Closure` can inspect lifecycle records and perform closure verification. Direct changes to source issue status or remediation links, and deletion of registered source issues, are rejected by model interfaces.

## State machine

The permitted path is:

1. `open` → `in_remediation`: a lifecycle manager selects a remediation project they belong to. The server creates and links a remediation task, assigns its due date, priority, and optional assignee, and snapshots the task in the transition history.
2. `in_remediation` → `verification`: the linked task must still exist and have status `Completed`, `Closed`, `Done`, or `Resolved`. The server locks the issue, lifecycle, and task before deriving eligibility.
3. `verification` → `closed`: a user with `Verify Issue Closure` records a verification summary and selects between one and 20 attachments from accepted data-request responses. The verifier must be different from the issue owner and the remediation task owner and assignee, must already be authorized to download every selected attachment, and the task must remain complete under the closure lock. The server locks the evidence records, confirms accepted response state and readable content on the configured private disk, and snapshots each file's identity, name, path, actual byte size, disk, and SHA-256 digest. Hashing is request-bounded to 10 MiB per file and 50 MiB total.
4. `closed` → `open`: a lifecycle manager supplies a reopening rationale. Prior closure and task state remain in the immutable transition snapshot; the current verification fields are cleared for the next cycle.

All other transitions are rejected. Source-domain derived state continues to treat `open`, `in_remediation`, and `verification` as unresolved; only `closed` clears the persistent action-required signal.

## Operator and integration surfaces

The **Remediation → Governance Issues** workspace provides owner-scoped discovery, current status, remediation linkage, closure details, actions allowed by permission and state, append-only transition and closure-evidence history, and private-disk export. Evidence metadata is part of the lifecycle record. Downloads bind the exact closure-evidence and attachment identities, recheck lifecycle and file authorization, and stream the snapshotted disk/path rather than resolving a raw path. The source issue remains visible in its existing domain workspace.

REST uses the source aliases `risk`, `vendor`, `ai`, `resilience`, `control_test`, `policy_exception`, and `third_party_collaboration`:

- `GET /api/governance-issues/{type}/{issue}`
- `POST /api/governance-issues/{type}/{issue}/remediation`
- `POST /api/governance-issues/{type}/{issue}/request-verification`
- `POST /api/governance-issues/{type}/{issue}/close`
- `POST /api/governance-issues/{type}/{issue}/reopen`

When PPM suite publishing is enabled, a newly created remediation task uses the existing best-effort, post-commit publishing path. The governed local task and transition do not depend on external publishing success.

## Evidence boundary and limitations

Closure requires governed audit evidence and stores an attributable verification summary. For each selected attachment, Fynix proves that the data-request response was accepted, the verifier was authorized to access it, and the file content was readable at closure; while hashing within the request bounds, it retains a dedicated content copy and records immutable attachment, response, request, audit, accepted-status, metadata, actual-size, disk, copy-path, and SHA-256 snapshots through product interfaces. Database foreign keys and model/file-interface guards prevent product deletion or repointing of referenced attachment records; replacement or deletion of the source upload does not alter the retained closure copy. Reopening retains prior closure-evidence snapshots.

An optional external `evidence_reference` remains unverified text. A content hash establishes byte identity at closure, not truth, provenance beyond the accepted audit workflow, or control effectiveness. Content download remains independently authorized and may fail if a storage or database administrator bypasses product controls and removes the object.

Fynix does not automatically select treatments, execute corrective actions, infer effectiveness from telemetry, waive issues, or close issues when a task changes. Remediation task completion, evidence selection, and lifecycle transitions remain deliberate operator actions. Automatic remediation and broader evidence ingestion for upstream assessments and monitoring remain separate gaps in the IRM evidence ledger.
