# Plan: Enterprise features

Source: [docs.opengrc.com/enterprise](https://docs.opengrc.com/enterprise/ai-audit/). Product context: `CONTEXT.md`, `PRD.md`.

This is a build plan, not a rewrite. The community spine (standards, controls, implementations, audits, risk register, vendor surveys, Trust Center, MCP) already exists. Enterprise is five **operator products** that sit on that spine, plus a small AI platform they all share.

**Do not** build a second PPM. Remediation projects here are POA&Ms tied to *this* GRC — not Fynix PPM portfolios.

---

## 1. Gap vs docs

| Upstream module | Docs | In this repo | Verdict |
|---|---|---|---|
| MCP Server | [enterprise/mcp-server](https://docs.opengrc.com/enterprise/mcp-server/) | `FynixCyberAuditServer`, OAuth DCR, AI Settings toggle, `/mcp/fynixcyberaudit` | **Shipped.** Extend later with Incidents / Projects tools. |
| AI suggestions / quota | Settings > AI | `AiService`, `QuotaService`, provider keys | **Partial.** Chat completion only; no structured JSON, no evidence search, no job progress. |
| AI-Powered Audits | [enterprise/ai-audit](https://docs.opengrc.com/enterprise/ai-audit/) | Audit Workflow has start/complete only | **Missing.** |
| Surveyor | [enterprise/surveyor](https://docs.opengrc.com/enterprise/surveyor/) | Vendor *outbound* surveys exist; no inbound questionnaire responder | **Missing.** |
| Risk Assessor | [enterprise/risk-assessor](https://docs.opengrc.com/enterprise/risk-assessor/) | Manual `Risk` register + heatmaps | **Missing.** Assessment campaign is a new entity. |
| Project Management (remediation) | [enterprise/project-management](https://docs.opengrc.com/enterprise/project-management/) | No project/task models | **Missing.** |
| Incident Management | [enterprise/incident-management](https://docs.opengrc.com/enterprise/incident-management/) | Policy/demo text only | **Missing.** Largest net-new domain. |

---

## 2. Shared platform (Phase 0) — do this first

Every AI feature above needs the same four primitives. Build them once.

### 0.1 Structured completion

Extend `app/Services/Ai/AiService.php`:

- `chatJson(string $system, string $user, array $schema): array` — fail closed if JSON does not validate.
- Pass `response_format` / tool-call where the provider supports it.
- Always return `{ content, model, usage: { prompt_tokens, completion_tokens } }`.
- Record usage through `QuotaService` (prompt + response). Refuse before the call if quota would blow.

### 0.2 Evidence index

New seam `app/Ai/EvidenceSearch.php`:

```
search(string $query, int $limit): list<EvidenceHit>
```

Hits are `{ type: policy|implementation|control|standard|document, id, title, excerpt, score }`. v1: MySQL/SQLite full-text or `LIKE` over policy body/purpose, implementation details, control description. v2: chunked embeddings. Surveyor and Risk Assessor must not each invent a searcher.

### 0.3 Background AI jobs

Convention for all long runs:

- `ai_jobs` table: type, subject, status, total, processed, failed, result_path, error, created_by.
- One queued job per item (or small batches), progress column, Filament widget poll 5s, database notification on complete/fail.
- Cancel sets status; workers check before the next item.

### 0.4 Feature flags + permissions

Env (and settings) per module, default **off** until the module ships:

- `MODULE_AI_AUDIT_ENABLED`
- `MODULE_SURVEYOR_ENABLED`
- `MODULE_RISK_ASSESSOR_ENABLED`
- `MODULE_REMEDIATION_ENABLED`
- `MODULE_INCIDENTS_ENABLED`
- `MODULE_RESILIENCE_ENABLED`

Spatie permissions per module (list/create/read/update/delete + manage). Super Admin gets all; Security Admin operate; Regular User read where it makes sense.

### 0.5 Design

Light chrome, tokens only, one mint CTA, RAG chips with labels. No new brand hue. Pages live in the **app** panel, nav group **Apps** (Surveyor, Risk Assessor) or domain groups (Incidents, Remediation).

**Exit Phase 0:** unit tests for JSON parse fail-closed, quota refuse, evidence search returning known policy text. No UI yet.

---

## 3. Phase 1 — AI-Powered Audits

Smallest enterprise win. Hooks the existing Audit Workflow.

**Who:** Audit manager on an **In Progress** audit.

**Action:** Workflow → “Perform AI Audit”. Confirm: ~1 min/item, implementations + policies only (not file evidence).

**Job:** For each *control-based* audit item (skip implementation-only):

1. Load control text + linked implementations (status/effectiveness) + related policies via EvidenceSearch.
2. Ask AI for `{ effectiveness, applicability, confidence, needs_human_review, notes }`.
3. Write auditor notes as `[AI Assessment - Confidence: HIGH|MEDIUM|LOW] …`.
4. LOW confidence always sets needs-human-review.
5. Credit: Implemented = 1, Partial = 0.5, Not = 0. Policies cap at 1 “point” of help.

**UI:** Progress widget on the audit view. Notification when done. Item form already has effectiveness/applicability/notes — do not invent a second assessment panel.

**Guardrails:** Only manager (or Super Admin). Audit must be In Progress. Respect quota. Human can overwrite every field.

**Tests:** Feature test with stubbed `AiService` — one control item updated, implementation-only skipped, non-manager 403, quota exceeded does not mutate items.

**PR:** `feat/ai-audit`

---

## 4. Phase 2 — Surveyor (inbound questionnaire responder)

Different from vendor surveys. Vendor surveys *ask* vendors. Surveyor *answers* questionnaires using *our* posture.

**Page:** `/app` → Apps → Survey Responder.

**Single question:** textarea → Generate → card with verdict / confidence / coverage / rationale / evidence used / needs-human-review. Editable before copy.

**Batch:** CSV with required `question` column (keep extra columns). Max 10 MB / 100 rows (env). Queue. History table: status, progress, cancel, download enriched CSV.

**Verdicts:** Meets | Partially meets | Does not meet.

**Config:** `SURVEYOR_MAX_FILE_SIZE`, `SURVEYOR_MAX_BATCH_QUESTIONS`, `SURVEYOR_TIMEOUT_PER_QUESTION` (default 120s).

**Reuse:** EvidenceSearch + AiService + ai_jobs + quota.

**Tests:** Stubbed AI for one question; CSV job writes output columns; cancel stops further items; missing `question` column rejected.

**PR:** `feat/surveyor`

---

## 5. Phase 3 — Risk Assessor

A **campaign**, not a replacement for the Risk register.

**New models:** `RiskAssessment` (title, owner, mode guided|manual, status, recurrence), `RiskAssessmentItem` (risk snapshot, inherent/residual, treatment, justification, ai_meta JSON), `RiskAssessmentCollaborator` (owner/contributor/reviewer).

**Wizard (5 steps, as docs):**

1. Select risks — guided questionnaire (10 profile sections) + AI recommend; or manual pick/create from register. Optional “discover from implementations.”
2. Assessment method (defaults).
3. Evaluate one-by-one: risk card, linked implementations (accept/reject AI suggestions), residual L/I buttons, treatment + justification, “Assess with AI.”
4. Review table; promote selected rows to `Risk`.
5. PDF (reuse heatmap rendering from `RiskLevel::getHeatmapHex`) + finalize.

**AI residual scoring (docs formula):** weight implementations by status/effectiveness; policies ≤ 1 point; return residual L/I, treatment, extra implementation suggestions, confidence 0–1.

**Recurrence:** monthly / quarterly / semi-annual / annual — scheduled command clones a completed assessment.

**Scoring bands:** keep **this product’s** register bands (1–4 / 5–8 / 9–12 / 13–17 / 18–25) so Assessor and register do not disagree. Do not silently adopt the upstream 13–16 / 17–25 split.

**Promote:** create or update `Risk` + attach accepted implementations. Never write the register until the user promotes.

**Tests:** wizard persist; promote creates Risk; AI stub fills residual; non-collaborator 403; recurrence command clones.

**PR:** `feat/risk-assessor`

---

## 6. Phase 4 — Remediation projects (POA&M)

**New models:** `RemediationProject` (code `RP-###`, name, status planning|active|on_hold|completed|archived, owner, optional program_id, dates), `RemediationTask` (number `RP-015-001`, status, priority, type, owner, assignee, due, weakness_description, `audit_item_id` nullable), `RemediationProjectMember`.

**UI:** list → project with Summary / Board / List tabs. Kanban drag updates status. Membership scopes visibility (admins see all).

**Bridge:** on Audit Item, “Create Remediation Task” — pick project, priority, assignee; prefill title + weakness from auditor notes; keep `audit_item_id`.

**Notify:** assignee on create/reassign; owner on status change.

**Statuses:** Open, In Progress, On Hold, Blocked, In Review, Completed, Cancelled, Risk Accepted.

**Types:** Remediation, Enhancement, Maintenance, Risk Acceptance, Investigation, Documentation.

**Not in scope:** Gantt, portfolios, timesheets, PPM sync. If HQ needs a project in PPM, that is a later integration, not this module.

**Tests:** member cannot see other projects; task from audit item stores FK; drag status persists.

**PR:** `feat/remediation-projects`

---

## 7. Phase 5 — Incident Management

Largest module. SANS 6-phase IR. Own nav group.

**Models:**

- `Incident` — number `INC-YYYY-####`, type, severity, status, phase, lead, reporter, detected_at, data/PII/breach flags, root cause, business impact, closure, phase timestamps.
- `IncidentType`, `IncidentPlaybook`, `IncidentPlaybookTask`
- `IncidentTask` (phase, status, priority, assignee, due)
- `IncidentTimelineEntry` (type, visibility internal|auditor, pinned, attachments)
- `IncidentEvidence` (type, hash, phase, source, chain-of-custody flag)
- `IncidentNotification` (regulator/individuals/partner/insurance/LE, framework, deadline, status)
- `IncidentLesson` (area, status)
- Pivot: incidents ↔ assets, applications, vendors, controls (with failure note), risks

**Create:** optional playbook clones tasks. Status Open, phase Identification.

**Workspace tabs:** Overview, Timeline, Tasks, Evidence, Notifications, Lessons, Report (PDF).

**Rules:** phase advance is timestamped and **not reversible** in v1 (docs). Timeline system events on phase/severity/assignment. Evidence hash on upload (`FileAccess`). GDPR-style deadline highlighting (72h) is display + overdue status, not a legal engine.

**PDF:** summary, tasks by phase, evidence manifest, notification status, affected entities, auditor-visible timeline only.

**Permissions:** list/read/create/update/delete incidents; manage playbooks; manage evidence; manage breach notifications; manage incident tasks.

**Tests:** playbook seeds tasks; phase advance writes timestamp and refuses reverse; evidence hash stored; PDF excludes internal-only timeline.

**PR:** `feat/incidents` (split playbooks vs workspace if the PR is huge).

---

## 8. Phase 6 — MCP + polish

After Incidents and Projects exist:

- `ManageIncidentTool`, `ManageRemediationProjectTool`, `ManageRemediationTaskTool` on `FynixCyberAuditServer` (same list/get/create/update/delete contract).
- Prompts: incident summary, remediation status.
- Still per-user OAuth; still permission-checked.
- Update `docs/mcp-server.md` and AI Settings copy.

**PR:** `feat/mcp-enterprise-entities`

---

## 9. Suggested sequence and sizing

```
P0  AI platform          3–5 days    blocks everything AI
P1  AI Audit             3–4 days    first visible Enterprise button
P2  Surveyor             5–7 days    quota + jobs get a second consumer
P3  Risk Assessor        8–12 days   wizard + promote + PDF
P4  Remediation          6–8 days    closes the audit loop
P5  Incidents            10–15 days  new domain
P6  MCP extensions       2–3 days
```

Ship behind flags. Do not enable all modules in production until P1+P2 have been used on real data.

---

## 10. Cross-cutting rules (non-negotiable)

- Staff-only modules use the app panel + existing OIDC. Vendor portal and Trust Center stay out.
- No `Model::unguard()`. Fillable + policies.
- Files through `FileAccess`.
- Tokens only in UI (`design.md`). Light mode.
- Every AI write is labeled as AI and overridable.
- Queue worker is required (`DEVELOPMENT.md`). Document it on each AI screen.
- Tests stub `AiService` / `OidcClient`. No live model calls in CI.

---

## 11. First ticket (when you say go)

**P0.1** — `AiService::chatJson` + quota hook + fail-closed fixture tests.

Then **P1** — Workflow action on `ViewAudit` + `PerformAiAuditJob`.
