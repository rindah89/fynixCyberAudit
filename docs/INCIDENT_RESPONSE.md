# Governed incident response foundation

Fynix Cyber Audit provides a deliberately operated cyber-incident register with playbook capture and attributable forward-only response-phase history. It is separate from ITSM tickets and major incidents and does not discover or automatically respond to security events.

## Workflow

1. An incident manager selects an existing playbook and records a title, severity, detection time, optional type, and data/PII/breach flags.
2. The server allocates `INC-YYYY-NNNN` under a year-scoped database lock, sets the reporter and lead to the submitting actor, captures the complete current playbook/task definition, seeds working tasks, and appends the initial `Identification` transition.
3. An incident manager advances exactly one SANS response phase at a time: `Identification`, `Containment`, `Eradication`, `Recovery`, then `Lessons Learned`.
4. Every transition retains the prior and new phase, required summary, complete material incident/playbook snapshot, actor, timestamp, and SHA-256 fingerprint. Transition rows cannot be edited or deleted through product interfaces.
5. Authorized readers inspect bounded paginated incident history through REST or **Incidents → Incidents**. The operator detail exposes the captured playbook and complete governed phase history.

The current incident record remains operational state. The append-only transition snapshot is the evidence of what the register contained at each phase decision. A later change does not rewrite earlier transition evidence.

Incidents created before this governed lifecycle are visibly labeled `legacy`. They remain readable for compatibility but have no server-captured creation baseline and cannot enter governed phase transition history. Fynix does not represent their earlier phase timestamps as governed evidence.

## Authorization and integrity

- The enterprise incidents module must be enabled.
- `Create Incidents` or `Manage Incidents` creates an incident. `Update Incidents` or `Manage Incidents` advances phases.
- `List Incidents`/`Read Incidents` or `Manage Incidents` controls list/detail inspection.
- The service repeats instance authorization after locking the current incident, so direct callers cannot bypass the interface policy.
- Caller-supplied numbers, phase, status, attribution, timestamps, snapshots, and fingerprints are rejected.
- Phase changes cannot skip or reverse phases.
- Legacy incidents cannot append governed phase transitions.
- Playbook identity/content and seeded task definitions are captured under locks with creation.
- Database administrators and storage administrators remain outside product-interface immutability guarantees.

## REST interface

- `GET /api/incidents?page=1&per_page=50`
- `POST /api/incidents`
- `GET /api/incidents/{incident}`
- `POST /api/incidents/{incident}/phase-transitions`

List pages accept one to 100 records. Creation accepts deliberate operator facts; phase transition accepts the next phase and a summary.

## Explicit limitations

Severity, type, detection time, data/PII/breach flags, playbooks, tasks, and transition summaries are deliberate human inputs. Fynix does not discover incidents, validate breach or legal-notification status, ingest SIEM/EDR telemetry, calculate regulatory deadlines, send crisis communications, authenticate incident evidence, select response actions, execute containment/recovery, integrate automatically with ITSM major incidents, or prove response effectiveness. This foundation does not yet claim governed task completion, notification, lessons, report, or incident-file evidence workflows.
