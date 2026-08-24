# Governed compliance case management

## Scope

Enable the module with `MODULE_COMPLIANCE_CASES_ENABLED=true`. This is an application feature flag and does not create infrastructure. Fynix provides a deliberately operated, permission-scoped compliance case workspace for intake, triage, investigation, resolution, and independent closure.

## Governed lifecycle

1. A user with `Manage Compliance Cases` opens a case with a category, priority, allegation, intake rationale, and optional source/reporter references. The server owns the case number, opener, opening time, initial `New` state, initial complete snapshot, event version, and SHA-256 fingerprint.
2. A manager moves `New` to `Triaged` only with an active user who currently holds `Investigate Compliance Cases` and a triage summary.
3. The assigned user with `Investigate Compliance Cases`, or a manager, moves `Triaged` to `Investigating` and records attributable investigation state. Investigators cannot change assignment, due date, triage, or closure evidence.
4. An investigation may move to `Action Required`, return to `Investigating`, or move to `Resolved`. Action-required and resolved states require an investigation summary; resolution additionally requires a resolution summary.
5. `Resolved` moves to terminal `Closed` only through a user with `Manage Compliance Cases` who is neither the opener nor any current/prior assigned investigator or investigation/resolution decision actor. The closer must author a separate closure summary in that event.
6. Every material change appends a server-versioned complete material before/after snapshot, rationale, actor/time, and SHA-256 fingerprint. Current state remains operational; append-only events retain the evidence history.

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
- The operator workspace provides the same scoped register, full case detail, governed actions, paginated history, and immutable event inspection.

List and event pages accept one to 100 rows. Callers cannot supply server-owned numbers, lifecycle timestamps, versions, snapshots, attribution, or fingerprints.

## Explicit limitations

- Allegations, investigation facts, conclusions, resolutions, and closure judgments are deliberate authorized-user inputs. Fynix does not determine their truth, legal sufficiency, or investigative quality.
- This slice does not provide anonymous/public hotline intake, email ingestion, evidence-file collection, legal-hold/eDiscovery, interview management, remediation-task automation, external notification, regulatory reporting, qualified signatures, or investigation analytics.
- SHA-256 fingerprints identify the persisted event payload; they do not authenticate the underlying allegation, source reference, or conclusion.
- Database administrators remain outside product-interface immutability guarantees.
