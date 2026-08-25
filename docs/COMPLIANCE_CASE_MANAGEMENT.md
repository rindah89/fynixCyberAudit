# Governed compliance case management

## Scope

Enable the module with `MODULE_COMPLIANCE_CASES_ENABLED=true`. This is an application feature flag and does not create infrastructure. Fynix provides a deliberately operated, permission-scoped compliance case workspace for intake, triage, investigation, resolution, and independent closure.

## Governed authenticated intake

An active authenticated internal user may submit an immutable concern with title, category, priority, allegation, declared source channel, optional source reference/message, confidentiality flag, exact reporter identity, time, reference, and recursively canonicalized SHA-256 fingerprint. Only that reporter can inspect the safe status of their own submission; the reporter view omits the allegation, reporter snapshot, internal disposition snapshots, and created-case evidence.

`Manage Compliance Cases` users inspect the complete intake register. A manager other than the reporter records one terminal accepted or rejected disposition with rationale, actor/time, the exact intake snapshot, and fingerprint. Acceptance atomically opens and binds a governed compliance case whose source reference is the intake reference and whose opening event is retained in the disposition. Rejection retains the concern without creating a case. This authenticated product workflow is not an anonymous/public hotline, external mailbox, identity-proofing service, emergency channel, or anti-retaliation program.

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
10. Before terminal closure, a manager may issue one of at most 20 immutable internal preservation instructions to one to 100 named active custodians. The instruction retains canonical systems/data categories, scope, optional legal-basis reference, preservation start, exact current case/event, issuer, custodians, time, and SHA-256 fingerprint. Only each exact active custodian can acknowledge their assignment. A manager separated from the issuer may release the hold only after every still-active custodian has acknowledged; inactive custodians and their attribution remain retained. Every hold must be released before case closure.
11. Before a newly governed case can move from triage into investigation, the assigned investigator or a case manager submits one of at most 20 immutable investigation plans with one to 20 objectives, bounded scope, one to 50 procedures, current/future target date, rationale, exact current case/event, author, time, version, and fingerprint. A case manager separated from both author and assigned investigator records one terminal approval/rejection. Only the latest exact-current approved plan permits investigation. A changed case event or rejected plan requires a replacement. Pre-existing cases without the nullable planning-governance marker remain readable legacy records and are not represented as plan-governed.
12. During investigation or action-required work, the assigned investigator or a case manager records an immutable conclusion for each server-selected procedure in the approved plan. Each conclusion retains its server version, plan index/text, completed/exception-identified/not-applicable result, summary, optional findings/source reference, complete approved plan/review and exact current case/event snapshots, executor/time, and fingerprint. A case manager separated from the executor records one immutable supervisory approval or rework-required decision against the exact conclusion. Rework permits a later retained version, up to 20 versions per procedure. Newly plan-governed cases cannot resolve until the latest conclusion for every approved-plan procedure has independent approval.
13. After every latest procedure conclusion is independently approved, the assigned investigator or a case manager submits one of at most 20 immutable final investigation reports. Each report retains outcome, executive summary, analysis, findings, recommendations, the complete approved plan/review, latest conclusion/review chain, exact current case/event, source fingerprints, author/time, version, and fingerprint. A case manager separated from the author, assigned investigator, and every procedure executor/reviewer records one terminal approval/rejection. Only the latest approved report bound to the exact current sources permits resolution. A changed case event or rejected report requires a replacement. Pre-existing cases without the nullable reporting-governance marker remain readable legacy records and are not represented as report-governed.

Each case is bounded to 200 events. Case and event mutation is serialized under the case row lock; number allocation is serialized by a singleton mutex. Routine migration rollback retains governed case history.

## Authorization and privacy

- `Manage Compliance Cases` can open, inspect, assign, govern, resolve, and independently close cases subject to separation rules.
- `Read Compliance Cases` provides read-only access to all cases and complete history.
- `Investigate Compliance Cases` exposes only cases currently assigned to that investigator and permits only the investigator-owned transition scope.
- Users without one of those permissions receive no case list, record, event count, content, or history.
- REST, direct service calls, and the operator workspace apply the same current authorization boundary. Deactivated users remain attributable in retained snapshots and historical relationships.

## Interfaces

- `GET|POST /api/compliance-case-intakes`
- `GET /api/my-compliance-case-intakes`
- `POST /api/compliance-case-intakes/{intake}/decision`
- `GET|POST /api/compliance-case-intakes/{intake}/messages`

An exact authenticated reporter and compliance-case managers may exchange at most 100 immutable intake messages. Reporters may create and paginate only reporter-visible correspondence on their own intake; managers may also retain internal messages. Each message binds the complete intake, current disposition when present, actor, audience, version, time, and recursively canonicalized SHA-256 fingerprint. Internal messages are filtered before reporter pagination and counting. This is an authenticated in-product correspondence ledger, not anonymous/public messaging, external delivery, a read receipt, emergency response, or evidence that a message was understood or acted upon.
- `POST /api/compliance-case-intake-messages/{message}/acknowledge`

Only the exact current active reporter may acknowledge a reporter-visible message authored by another user, once. The immutable receipt retains the complete message evidence, material reporter identity, acknowledgement time, and recursively canonicalized SHA-256 fingerprint. Reporter history exposes only acknowledgement time/fingerprint; manager REST and operator inspection expose complete evidence. This proves an authenticated in-product acknowledgement action, not reading, comprehension, agreement, truth, external delivery, or investigative action.
- `GET|POST /api/compliance-cases`
- `GET /api/compliance-cases/{case}`
- `GET|POST /api/compliance-cases/{case}/events`
- `GET|POST /api/compliance-cases/{case}/evidence`
- `GET /api/compliance-cases/{case}/action-issues`
- `GET|POST /api/compliance-cases/{case}/interviews`
- `GET|POST /api/compliance-cases/{case}/investigation-plans`
- `POST /api/compliance-case-investigation-plans/{plan}/review`
- `GET|POST /api/compliance-cases/{case}/investigation-procedure-executions`
- `POST /api/compliance-case-investigation-procedure-executions/{execution}/review`
- `POST /api/compliance-cases/{case}/interviews/{interview}/events`
- `GET|POST /api/compliance-cases/{case}/legal-holds`
- `POST /api/compliance-cases/{case}/legal-holds/{hold}/release`
- `GET /api/my-compliance-case-legal-holds`
- `POST /api/compliance-case-legal-holds/{hold}/acknowledge`
- `GET|POST /api/governance-issues/compliance_case_action/{issue}` and its remediation, verification, close, and reopen actions
- `GET /app/compliance-case-evidence/{evidence}/download`
- The operator workspace provides the same scoped register, full case detail, governed actions, paginated history, and immutable event inspection.

List and event pages accept one to 100 rows. Callers cannot supply server-owned numbers, lifecycle timestamps, versions, snapshots, attribution, or fingerprints.

## Explicit limitations

- Allegations, investigation facts, conclusions, resolutions, and closure judgments are deliberate authorized-user inputs. Fynix does not determine their truth, legal sufficiency, or investigative quality.
- Selected evidence remains a deliberate input. Retained-byte hashes prove identity, not truth, authenticity, relevance, sufficiency, legal admissibility, or investigative quality. Exact downloads independently require current case-workspace and source-attachment access; database rollback invokes compensating retained-copy cleanup, while storage-adapter/administrator failures remain outside that guarantee.
- Interview subjects, purpose, notes, summaries, and cancellation decisions are deliberate authorized-user inputs. Fynix does not record audio/video, transcribe, authenticate participants, compel attendance, determine credibility, infer findings, provide privilege/legal advice, or prove interview quality.
- Investigation objectives, scope, procedures, target dates, rationale, and approval are deliberate authorized-user decisions. Approval proves only that a separated manager recorded the configured plan decision; it does not prove investigative quality, legal sufficiency, plan execution, evidence collection, completeness, timeliness, truth, or outcome effectiveness.
- Procedure results, summaries, findings, not-applicable conclusions, source references, and supervisory decisions are deliberate authorized-user inputs. Approval proves only that a separated manager recorded the configured review decision; it does not prove the work occurred, validate sources, infer findings, establish truth or legal sufficiency, establish reviewer competence, or demonstrate investigation quality/effectiveness.
- Legal-hold scope, systems, data categories, legal-basis references, acknowledgement statements, and release judgments are deliberate internal instructions and assertions. Fynix does not discover, collect, suspend deletion in, or verify preservation within source systems; it does not provide litigation hold delivery, custodian surveys, eDiscovery processing, legal advice, or proof of legal sufficiency/compliance.
- Authenticated concern submission is delivered, but anonymous/public hotline intake, email ingestion, emergency response, identity proofing, anti-retaliation administration, automated source-system preservation or eDiscovery, automatic remediation execution, external notification, regulatory reporting, qualified signatures, and investigation analytics remain absent. Remediation tasks, their completion state, and verification judgments remain deliberate governed inputs.
- SHA-256 fingerprints identify the persisted event payload; they do not authenticate the underlying allegation, source reference, or conclusion.
- Database administrators remain outside product-interface immutability guarantees.
