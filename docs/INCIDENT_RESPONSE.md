# Governed incident response foundation

Fynix Cyber Audit provides a deliberately operated cyber-incident register with playbook capture and attributable forward-only response-phase history. It is separate from ITSM tickets and major incidents and does not discover or automatically respond to security events.

## Workflow

1. An incident manager selects an existing playbook and records a title, severity, detection time, optional type, and data/PII/breach flags.
2. The server allocates `INC-YYYY-NNNN` under a year-scoped database lock, sets the reporter and lead to the submitting actor, captures the complete current playbook/task definition, seeds working tasks, and appends the initial `Identification` transition.
3. An incident manager advances exactly one SANS response phase at a time: `Identification`, `Containment`, `Eradication`, `Recovery`, then `Lessons Learned`.
4. Every transition retains the prior and new phase, required summary, complete material incident/playbook snapshot, actor, timestamp, and SHA-256 fingerprint. A later phase decision may also bind up to 20 accepted audit attachments currently accessible to the manager; bounded retained copies and their provenance/SHA-256 manifests are included in that decision fingerprint. Transition and evidence rows cannot be edited or deleted through product interfaces.
5. Authorized readers inspect bounded paginated incident history through REST or **Incidents → Incidents**. The operator detail exposes the captured playbook and complete governed phase history.
6. Up to 100 tasks are seeded from the captured playbook. An incident manager may assign/reassign a task, set a current/future due date, and move it through `Open`, `In Progress`, `Blocked`, `Completed`, or `Cancelled`; the current assignee may change only its status. Work cannot start before the incident reaches the task's configured response phase, and completed/cancelled tasks are terminal.
7. Every governed task starts with a seed event. Each later mutation retains a server version, before/after task and incident-phase snapshots, summary, actor/time, and SHA-256 fingerprint. A task retains at most 100 events.
8. A later task event may bind up to 20 accepted data-request attachments currently accessible to its actor. The server locks their accepted provenance, streams and retains dedicated copies within 10 MiB/file and 50 MiB/request limits, and binds immutable source identities, actual size, retained location, and SHA-256 manifests into the event fingerprint. A failed selection or copy rolls back the task mutation, event, and evidence rows; successfully written uncommitted copies are tracked and cleaned through compensating storage deletion.
9. A user with `Manage Breach Notifications` may deliberately register up to 100 notification audiences per governed incident and record a forward-only assessment lifecycle: `Assessment Pending` → `Required` or `Not Required`; required records may become `Prepared`, then `Sent`. Cancellation is terminal. Required/prepared records need an operator-supplied deadline; sent status needs an external delivery reference. Each record retains at most 50 attributable, immutable before/after decision snapshots and fingerprints. Fynix derives pending/overdue display state but does not calculate a legal deadline or send an external notification.
10. Once the incident reaches `Lessons Learned`, an incident manager may register up to 100 deliberate lessons across people, process, technology, communication, vendor, governance, or other areas. Each captures an observation, recommendation, active accountable owner, optional current/future target date, and rationale. The manager or owner advances `Proposed` → `In Progress` → `Implemented`, or terminally closes it without action; owners may change status only. Each lesson retains at most 50 attributable before/after snapshots and fingerprints. Target status is derived for display, while implementation remains an operator assertion rather than effectiveness proof.

The current incident record remains operational state. The append-only transition snapshot is the evidence of what the register contained at each phase decision. A later change does not rewrite earlier transition evidence.

Incidents created before this governed lifecycle are visibly labeled `legacy`. They remain readable for compatibility but have no server-captured creation baseline and cannot enter governed phase transition history. Tasks created before the governed task lifecycle are likewise labeled `legacy` and cannot append governed task events. Fynix does not represent earlier phase timestamps or task changes as governed evidence.

## Authorization and integrity

- The enterprise incidents module must be enabled.
- `Create Incidents` or `Manage Incidents` creates an incident. `Update Incidents` or `Manage Incidents` advances phases.
- `List Incidents`/`Read Incidents` or `Manage Incidents` controls list/detail inspection.
- The service repeats instance authorization after locking the current incident, so direct callers cannot bypass the interface policy.
- Caller-supplied numbers, phase, status, attribution, timestamps, snapshots, and fingerprints are rejected.
- Phase changes cannot skip or reverse phases.
- Caller-supplied phase evidence manifests are rejected. Selected files must be distinct attachments on accepted responses that the actor can currently download; exact retained-copy downloads independently require current incident-view plus source-attachment access.
- Legacy incidents cannot append governed phase transitions.
- Only an incident manager or the current task assignee can append task events; assignees cannot change assignment or due dates.
- Current assignees may inspect their assigned task's governed history without receiving the broader incident workspace; incident readers and task managers may also inspect it.
- Task mutations lock the incident and task before current reauthorization; any selected active assignee is then locked and validated before mutation.
- Caller-supplied event versions, snapshots, attribution, timestamps, and fingerprints are rejected.
- Caller-supplied evidence manifests are rejected. Evidence selection accepts only distinct attachment IDs from accepted responses that the actor can currently download.
- Exact retained-copy downloads independently require current task-workspace access and current access to the linked source attachment. REST and operator history omit unauthorized evidence existence and metadata.
- Source attachment identity and deletion are protected while governed task-event evidence references it; retained copies remain independent of later source-byte replacement.
- `Manage Breach Notifications` is independently required for notification registration and decisions. Incident readers may inspect the current register and paginated evidence history but cannot change it.
- The notification service repeats permission and governed-incident checks after locking the incident and notification record. Caller-owned status defaults, versions, snapshots, actor/time, sent time, and fingerprints are rejected.
- Sent, not-required, and cancelled records are terminal. A delivery reference is an unverified operator-supplied pointer; it is not delivery proof.
- Lessons can be registered only after the governed incident reaches `Lessons Learned`. The service locks incident then lesson, repeats current authorization, validates an active owner, and restricts owners to status-only progress.
- An accountable lesson owner without incident-read permission receives the lesson event but not its embedded incident snapshot; incident readers and the authorized operator workspace receive complete evidence.
- Implemented and closed-without-action lessons are terminal. Caller-supplied status defaults, versions, snapshots, attribution, times, and fingerprints are rejected.
- Playbook identity/content and seeded task definitions are captured under locks with creation.
- Database administrators and storage administrators remain outside product-interface immutability guarantees.

## REST interface

- `GET /api/incidents?page=1&per_page=50`
- `POST /api/incidents`
- `GET /api/incidents/{incident}`
- `POST /api/incidents/{incident}/phase-transitions`
- `POST /api/incident-tasks/{task}/events`
- `GET /api/incident-tasks/{task}/events?page=1&per_page=50`
- `GET|POST /api/incidents/{incident}/notifications`
- `POST /api/incident-notifications/{notification}/decisions`
- `GET /api/incident-notifications/{notification}/events?page=1&per_page=50`
- `GET|POST /api/incidents/{incident}/lessons`
- `POST /api/incident-lessons/{lesson}/progress`
- `GET /api/incident-lessons/{lesson}/events?page=1&per_page=50`

List pages accept one to 100 records. Creation accepts deliberate operator facts; phase transition accepts the next phase, a summary, and optional `evidence_attachment_ids` containing up to 20 distinct accepted attachment IDs. Phase and task evidence responses/history include only evidence authorized under the exact attachment ACL, and retained content uses the authenticated link exposed there. Task-event mutation accepts a required summary, at least one governed state change, and the same optional evidence selection. Notification registration requires audience, recipient, and rationale; framework and deadline are optional until a `Required`/`Prepared` decision. Later decisions require a rationale and at least one material state/context change. Lesson registration requires area, observation, recommendation, owner, and rationale; target date is optional. Progress requires rationale plus a permitted status or manager-owned content/accountability change.

## Explicit limitations

Severity, type, detection time, data/PII/breach flags, playbooks, assignments, due dates, task states, evidence selection, notification audience/framework/recipient/deadline/determination, delivery references, lesson content/owners/targets/statuses, and summaries are deliberate human inputs. Hashes identify retained bytes; they do not prove evidence truth, sufficiency, authenticity, relevance, task completion, response effectiveness, or that a state decision was derived from a file. Notification history proves only what an operator recorded in Fynix; it does not provide legal advice, determine whether notice is required, calculate statutory deadlines, authenticate submission references, or prove receipt/readership. Lesson status does not prove recommendation implementation or effectiveness, and Fynix does not automatically create remediation work from lessons. Fynix does not discover incidents, ingest SIEM/EDR telemetry, send regulator/individual/partner/insurer/law-enforcement notices, send crisis communications, select response actions, execute containment/recovery, or integrate automatically with ITSM major incidents. Storage-adapter or administrator failures, including a failed compensating deletion, remain outside the product transaction guarantee. This foundation does not yet claim stakeholder delivery automation, lesson-to-remediation automation, or final incident-report workflows.
