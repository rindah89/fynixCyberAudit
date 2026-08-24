# Operational resilience foundation

Fynix Cyber Audit connects critical business services to approved impact objectives, typed dependencies, recovery plans, scenario exercises, actual-disruption continuity activations, and exercise issues. This is a governed planning, exercise, and recovery-decision workflow, not real-time service monitoring or automated business-continuity orchestration.

The enterprise module is enabled with `MODULE_RESILIENCE_ENABLED=true`. This configuration key must be recorded in a deployment handoff; enabling it does not require or create infrastructure.

## Workflow

1. An operator with `Manage Resilience` creates a business service and assigns an accountable owner.
2. The operator records a business-impact analysis with maximum tolerable downtime (MTD), recovery time objective (RTO), recovery point objective (RPO), impact ratings, rationale, and optional hourly financial impact. Fynix allocates monotonically increasing versions. Submitted analyses are append-only through product interfaces; revisions create a new version.
3. The operator maps dependencies to exactly one existing business service, application, asset, vendor, or control per dependency record.
4. After an impact analysis is approved, the operator creates a recovery plan with activation criteria, recovery and communication procedures, an owner, and a current or future review date. Fynix allocates the version. Approved or exercised plans are append-only through product interfaces.
5. An approved plan is exercised against a documented scenario and may optionally reference an existing incident.
6. On completion, Fynix snapshots the approved RTO/RPO, derives `passed`, `partial`, or `failed`, and opens a resilience issue when either objective is missed. An authorized completer may bind one to 20 accepted audit-evidence attachments they can access; Fynix retains bounded copies and snapshots provenance, metadata, actual size, disk/path, SHA-256, actor, and time.
7. Open **Operational Resilience → Business Services** to review service readiness, impact analyses, dependencies, plans, complete exercise history, governed evidence, and issues. Service owners have owner-scoped read access; governance changes require `Manage Resilience`. Evidence counts, actions, metadata, and downloads are visible only when the viewer also passes the exact attachment ACL.
8. For a real disruption, a resilience manager activates an approved plan against its locked service and approved impact analysis. Only one active recovery may exist per service. The lifecycle advances `Activated` → `Recovering` → `Restored` → `Closed`, or may terminate as `Cancelled` before restoration. Restoration time is server-derived from the reported disruption start and compared with the retained RTO; the operator records actual recovery-point loss for comparison with the retained RPO.
9. Every activation event retains a complete point-in-time activation, business-service, approved-impact-analysis, and approved-plan snapshot, summary, actor, time, version, and SHA-256 fingerprint. At most 100 activations per service and 100 events per activation are retained. Scoped paginated REST and read-only operator history expose the evidence.

## Derived readiness

| Status | Meaning |
|---|---|
| `impact_analysis_required` | No approved impact analysis exists. |
| `recovery_plan_required` | An approved analysis exists but no approved recovery plan exists. |
| `plan_review_overdue` | The latest approved plan is past its review date. |
| `exercise_required` | The approved plan has no completed exercise. |
| `ready` | The latest completed exercise met both objectives, or every issue opened by a missed objective has been independently verified and closed. |
| `action_required` | At least one resilience issue remains `open`, `in_remediation`, or `verification`; this takes priority over other active-service readiness states. A failed exercise without a matching governed issue also fails closed to this state. |
| `inactive` | The business service is not actively governed. |

Exercise results use the latest approved impact analysis at completion time and persist the objective snapshot. Later BIA versions do not rewrite historical exercise results.

## Integrity and evidence boundaries

- Callers cannot submit exercise outcomes or objective snapshots; the server derives them.
- Completed exercises, approved analyses, and approved plans cannot be changed through product interfaces. Database administrators remain outside this application-level guarantee.
- Each analysis, plan approval, and exercise records an attributable user and timestamp.
- `evidence_reference` is optional, unverified operator-supplied text. Separately, completion may deliberately bind accepted audit attachments. Retained-copy SHA-256 values prove byte identity, not truth, sufficiency, authenticity, or that measured RTO/RPO values were derived from the file.
- Exercise issues are distinct from audit findings. They use the shared deliberate remediation lifecycle; closure requires accepted audit attachments with access, presence, size, and SHA-256 checks, but this foundation does not automatically create remediation work.

## REST interface

- `POST /api/business-services`
- `POST /api/business-services/{service}/impact-analyses`
- `POST /api/business-services/{service}/dependencies`
- `POST /api/business-services/{service}/recovery-plans`
- `POST /api/recovery-plans/{plan}/exercises`
- `POST /api/recovery-exercises/{exercise}/complete`
- `GET /api/business-services/{service}/continuity-activations`
- `POST /api/recovery-plans/{plan}/continuity-activations`
- `GET /api/continuity-activations/{activation}`
- `POST /api/continuity-activations/{activation}/events`

All endpoints require authentication, the enabled resilience module, and `Manage Resilience`.

## Explicit limitations

Disruption start, impact, recovery-point loss, summaries, and lifecycle decisions are deliberate operator inputs. The server-derived recovery duration proves only elapsed time between those recorded events. This foundation does not discover dependencies or disruptions, ingest uptime or disaster-recovery telemetry, automatically collect evidence, infer results from file content, calculate financial loss, run recovery procedures, validate restoration, send crisis communications, schedule exercises automatically, or validate evidence authenticity/sufficiency. Exercise issues use the separately documented governed remediation and independent-closure workflow with separate content-hashed accepted audit evidence. Actual-disruption outcome does not prove service availability, recovery execution, customer impact, or plan effectiveness. It is not a substitute for live availability monitoring, emergency notification, or automated continuity orchestration.
