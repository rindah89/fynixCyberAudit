# Audit universe and risk-based planning

Fynix Cyber Audit provides a deliberately maintained auditable-entity universe and ordinal risk-based audit-planning foundation. It complements the existing Program, Audit, AuditItem, data-request, finding, and remediation workflows; it does not automatically discover the organization or optimize audit resources.

## Auditable entities

Users with `Update Programs` register and update auditable entities through REST. Each record has a unique code, name, optional description, type, accountable owner, criticality, lifecycle status, assessment cadence, next-assessment date, and at least one mapped risk; mapped controls are optional. Types are `business_unit`, `business_process`, `legal_entity`, `technology`, `third_party`, `program`, and `other`. Criticality is `low`, `medium`, `high`, or `critical`.

The assigned owner or a user with `Update Programs` records an assessment. Likelihood and impact values use the existing 1–5 ordinal convention. The server derives inherent and residual 1–25 scores, rejects residual exposure above inherent exposure, and derives a deterministic priority band. Each append-only assessment snapshots the entity plus material mapped risk/control values, actor/time, rationale, next assessment date, and a SHA-256 fingerprint. Once assessment history exists, ordinary entity maintenance cannot move that due date; a new assessment is required. A material entity, risk, control, or mapping change produces `reassessment_required`; a passed due date produces `assessment_overdue`.

Priority is `critical` for residual score 20–25 or critical entities scoring at least 15; `high` for residual score 15–19 or any remaining critical entity; `medium` for residual score 8–14 or a high-criticality entity; otherwise `low`. These bands are transparent ordinal prioritization, not calibrated probability or quantitative loss.

## Risk-based audit plans

Users with `Update Programs` create a draft plan for a calendar year and assign a manager. The plan manager or a user with `Update Programs` adds an entity only with its current, non-stale assessment. Item status is `planned`, `scheduled`, or `deferred`; `scheduled` requires a linked existing `audit_id`. Planned dates must be inside the plan year. The server snapshots the complete assessment evidence and derives `priority_rank` as priority-band weight × 100 plus residual score.

Draft item dates, rationale, status, and optional audit link can be corrected, and a draft item can be removed. Approval requires at least one item and re-locks every entity, assessment, mapping, mapped risk/control, and linked audit to prove the evidence is still current. The server orders items by derived rank, records approver/time, snapshots the plan and every item, fingerprints that snapshot, and makes the approved plan and its items immutable through product interfaces. Changes after approval require a new plan record.

## Access and evidence surfaces

Users with `Read Programs` or `Update Programs` inspect the complete universe and plans. Entity owners see their assigned entities and assessment history; plan managers see their plans and items. **Foundations → Audit Universe** and **Foundations → Risk-Based Audit Plans** provide read-only, paginated inspection and relation-scoped private exports. REST lists are paginated with a maximum page size of 100.

## Approved-plan engagement handoff

After approval, a planned item without an existing audit can launch one audit engagement through REST. The caller must have `Create Audits` and must be either the approved plan manager or have `Update Programs`. The request supplies the audit title/type, accountable manager, optional program and description, explicit objective/scope/exclusions, and up to 100 active team members. The server uses the approved item dates, starts the audit in `Not Started`, ensures the manager is on the team, and creates the audit and team membership in the same transaction.

The handoff does not rewrite the approved plan item. Instead, it creates one immutable engagement baseline linking the new audit to the approved item. The baseline retains the plan approval identity/fingerprint, item priority and dates, complete entity-assessment snapshot, objective, scope, exclusions, sorted team identities, launcher/time, and a SHA-256 content fingerprint. The audit detail REST response, operator audit detail, and audit export expose that baseline. Subsequent audit execution may update the operational audit, but it does not rewrite what was authorized at launch.

## Limitations

All entities, mappings, scores, rationales, charter content, team selection, and launch actions are deliberate operator or integration inputs. Fynix does not discover entities, ingest risk telemetry, calculate calibrated likelihood or financial loss, optimize staffing/budgets, forecast assurance coverage, create audit procedures, allocate resources automatically, or automatically launch or execute audits. Snapshot hashes establish content identity, not the truth, sufficiency, or execution quality of the underlying assessment or charter.
