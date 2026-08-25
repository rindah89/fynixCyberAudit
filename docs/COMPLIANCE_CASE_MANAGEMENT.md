# Governed compliance case management

## Scope

Enable the module with `MODULE_COMPLIANCE_CASES_ENABLED=true`. This is an application feature flag and does not create infrastructure. Fynix provides a deliberately operated, permission-scoped compliance case workspace for intake, triage, investigation, resolution, and independent closure.

## Governed lifecycle

1. A user with `Manage Compliance Cases` opens a case with a category, priority, allegation, intake rationale, and optional source/reporter references. The server owns the case number, opener, opening time, initial `New` state, initial complete snapshot, event version, and SHA-256 fingerprint.
2. A manager moves `New` to `Triaged` only with an active user who currently holds `Investigate Compliance Cases` and a triage summary.
3. The assigned user with `Investigate Compliance Cases`, or a manager, moves `Triaged` to `Investigating` and records attributable investigation state. Investigators cannot change assignment, due date, triage, or closure evidence.
4. Moving to `Action Required` atomically opens one immutable governance issue bound to the exact case/event snapshot and fingerprint. The issue owner is the active assigned investigator. The shared governed lifecycle records remediation handoff, task state, verification, accepted evidence, and independent closure; the case cannot leave `Action Required` until every action issue is independently closed.
5. An independently closed action issue permits the case to return to `Investigating` or move to `Resolved`; reopening that issue is permitted only while the case remains `Action Required`. Resolved state additionally requires a resolution summary.
6. `Resolved` moves to terminal `Closed` only through a user with `Manage Compliance Cases` who is neither the opener nor any current/prior assigned investigator or investigation/resolution decision actor. The closer must author a separate closure summary in that event.
7. Every material change appends a server-versioned complete material before/after snapshot, rationale, actor/time, and SHA-256 fingerprint. Current state remains operational; append-only events retain the evidence history.
8. While a case remains open, its current assigned investigator or a manager may append up to 100 governed evidence submissions. Each submission selects one to 20 accepted audit attachments currently accessible to that actor, retains bounded private copies, and binds the exact current case/latest-event, actor, summary, manifest, time, version, and SHA-256 fingerprint without rewriting case decisions.
9. During triage, investigation, or action-required work, the current investigator or a manager may govern up to 100 interviews. Scheduling retains an internal subject identity or external reference, active authorized interviewer, time, location, purpose, case context, actor, and rationale. Rescheduling and the terminal conducted/cancelled decisions append complete before/after snapshots; conducted interviews require a deliberate summary and actual time, while cancellation requires a reason. Each interview is limited to 20 append-only events.

Each case is bounded to 200 events. Case and event mutation is serialized under the case row lock; number allocation is serialized by a singleton mutex. Routine migration rollback retains governed case history.

## Authorization and privacy

- `Manage Compliance Cases` can open, inspect, assign, govern, resolve, and independently close cases subject to separation rules.
- `Read Compliance Cases` provides read-only access to all cases and complete history.
- `Investigate Compliance Cases` exposes only cases currently assigned to that investigator and permits only the investigator-owned transition scope.
- Users without one of those permissions receive no case list, record, event count, content, or history.
- REST, direct service calls, and the operator workspace apply the same current authorization boundary. Deactivated users remain attributable in retained snapshots and historical relationships.

## Interfaces

- `GET|POST /api/compliance-cases`
- `GET /api/compliance-cases/{case}`
- `GET|POST /api/compliance-cases/{case}/events`
- `GET|POST /api/compliance-cases/{case}/evidence`
- `GET /api/compliance-cases/{case}/action-issues`
- `GET|POST /api/compliance-cases/{case}/interviews`
- `POST /api/compliance-cases/{case}/interviews/{interview}/events`
- `GET|POST /api/governance-issues/compliance_case_action/{issue}` and its remediation, verification, close, and reopen actions
- `GET /app/compliance-case-evidence/{evidence}/download`
- The operator workspace provides the same scoped register, full case detail, governed actions, paginated history, and immutable event inspection.

List and event pages accept one to 100 rows. Callers cannot supply server-owned numbers, lifecycle timestamps, versions, snapshots, attribution, or fingerprints.

## Explicit limitations

- Allegations, investigation facts, conclusions, resolutions, and closure judgments are deliberate authorized-user inputs. Fynix does not determine their truth, legal sufficiency, or investigative quality.
- Selected evidence remains a deliberate input. Retained-byte hashes prove identity, not truth, authenticity, relevance, sufficiency, legal admissibility, or investigative quality. Exact downloads independently require current case-workspace and source-attachment access; database rollback invokes compensating retained-copy cleanup, while storage-adapter/administrator failures remain outside that guarantee.
- Interview subjects, purpose, notes, summaries, and cancellation decisions are deliberate authorized-user inputs. Fynix does not record audio/video, transcribe, authenticate participants, compel attendance, determine credibility, infer findings, provide privilege/legal advice, or prove interview quality.
- This slice does not provide anonymous/public hotline intake, email ingestion, legal-hold/eDiscovery, automatic remediation execution, external notification, regulatory reporting, qualified signatures, or investigation analytics. Remediation tasks, their completion state, and verification judgments remain deliberate governed inputs.
- SHA-256 fingerprints identify the persisted event payload; they do not authenticate the underlying allegation, source reference, or conclusion.
- Database administrators remain outside product-interface immutability guarantees.
