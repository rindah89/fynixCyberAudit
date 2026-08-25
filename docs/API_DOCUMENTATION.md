# Fynix Cyber Audit API Documentation

## Overview

The Fynix Cyber Audit API provides RESTful endpoints for managing all resources in the GRC system. All endpoints require authentication via Laravel Sanctum tokens and respect the permission system based on user roles.

## Authentication

### Generate API Token

Users can generate API tokens from the Filament admin panel using the built-in Sanctum token management (Filament Breezy).

1. Log in to the Fynix Cyber Audit web interface
2. Navigate to your profile settings
3. Generate a new API token
4. Copy the token (it will only be shown once)

### Using the Token

Include the token in all API requests using the `Authorization` header:

```bash
Authorization: Bearer YOUR_TOKEN_HERE
```

## Rate Limiting

API requests are rate-limited to **60 requests per minute** per user (or IP address for unauthenticated requests).

Rate limit headers are included in all responses:
- `X-RateLimit-Limit`: Maximum requests allowed
- `X-RateLimit-Remaining`: Remaining requests in current window
- `Retry-After`: Seconds to wait before retrying (when rate limited)

## Permissions

All endpoints use Laravel policies for authorization, which check permissions via the Spatie Permission system. The API uses the same policies as the Filament web interface, ensuring consistent authorization across both.

Required permissions follow this pattern:

- `GET /api/resources` → `List {Resources}` permission (via `viewAny` policy)
- `POST /api/resources` → `Create {Resources}` permission (via `create` policy)
- `GET /api/resources/{id}` → `Read {Resources}` permission (via `view` policy)
- `PUT/PATCH /api/resources/{id}` → `Update {Resources}` permission (via `update` policy)
- `DELETE /api/resources/{id}` → `Delete {Resources}` permission (via `delete` policy)
- `POST /api/resources/{id}/restore` → `Update {Resources}` permission (via `restore` policy)

If a user lacks the required permission, the API returns a `403 Forbidden` response.

## Common Query Parameters

### Pagination

All index endpoints support pagination:

- `per_page` - Number of results per page (default: 15, max: 100)
- `page` - Page number (default: 1)
- `no_pagination` - Set to `true` to disable pagination and return all results

Example:
```bash
GET /api/standards?per_page=25&page=2
```

### Searching

Search across searchable fields:

- `search` - Search term to filter results

Example:
```bash
GET /api/controls?search=encryption
```

### Sorting

Sort results by any sortable field:

- `sort` - Field name to sort by
- `direction` - Sort direction (`asc` or `desc`)

Example:
```bash
GET /api/audits?sort=created_at&direction=desc
```

### Eager Loading

Load relationships using the `with` parameter:

```bash
GET /api/controls/1?with=standard,implementations
```

## Available Endpoints

### Users

**Base URL:** `/api/users`

- `GET /api/users` - List all users
- `POST /api/users` - Create a new user
- `GET /api/users/{id}` - Get a specific user
- `PUT /api/users/{id}` - Update a user
- `DELETE /api/users/{id}` - Delete a user (soft delete)
- `POST /api/users/{id}/restore` - Restore a soft-deleted user

**Searchable Fields:** `name`, `email`

**Relations:** `roles`, `permissions`, `managedPrograms`

**Required Permissions:**
- Only users with "Manage Users" permission can access these endpoints
- Password must meet security requirements: minimum 12 characters, mixed case, not previously compromised

**Create User Request:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "SecurePassword123!",
  "password_confirmation": "SecurePassword123!",
  "roles": ["Regular User"]
}
```

**Update User Request:**
```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "roles": ["Security Admin", "Internal Auditor"]
}
```

**Notes:**
- Passwords are hashed automatically using Laravel's secure hashing
- Password confirmation is required when creating or updating passwords
- Roles can be assigned/updated via the `roles` array
- Sensitive fields (password, remember_token) are hidden in responses
- Users are soft-deleted by default

### Standards

**Base URL:** `/api/standards`

- `GET /api/standards` - List all standards
- `POST /api/standards` - Create a new standard
- `GET /api/standards/{id}` - Get a specific standard
- `PUT /api/standards/{id}` - Update a standard
- `DELETE /api/standards/{id}` - Delete a standard
- `POST /api/standards/{id}/restore` - Restore a soft-deleted standard

**Searchable Fields:** `code`, `title`, `description`

**Relations:** `controls`, `programs`

### Controls

**Base URL:** `/api/controls`

- `GET /api/controls` - List all controls
- `POST /api/controls` - Create a new control
- `GET /api/controls/{id}` - Get a specific control
- `PUT /api/controls/{id}` - Update a control
- `DELETE /api/controls/{id}` - Delete a control
- `POST /api/controls/{id}/restore` - Restore a soft-deleted control

**Searchable Fields:** `identifier`, `title`, `description`, `standard.code`, `standard.title`

**Relations:** `standard`, `implementations`, `controlOwner`

### Implementations

**Base URL:** `/api/implementations`

- `GET /api/implementations` - List all implementations
- `POST /api/implementations` - Create a new implementation
- `GET /api/implementations/{id}` - Get a specific implementation
- `PUT /api/implementations/{id}` - Update an implementation
- `DELETE /api/implementations/{id}` - Delete an implementation
- `POST /api/implementations/{id}/restore` - Restore a soft-deleted implementation

**Searchable Fields:** `title`, `details`, `notes`

**Relations:** `controls`, `risks`, `assets`, `implementationOwner`

### Audits

**Base URL:** `/api/audits`

- `GET /api/audits` - List all audits
- `POST /api/audits` - Create a new audit
- `GET /api/audits/{id}` - Get a specific audit
- `PUT /api/audits/{id}` - Update an audit
- `DELETE /api/audits/{id}` - Delete an audit
- `POST /api/audits/{id}/restore` - Restore a soft-deleted audit

**Searchable Fields:** `title`, `description`, `audit_type`

**Relations:** `manager`, `standard`, `auditItems`

**Special Parameters:**
- `with_details=true` - Load all related audit items with full details

#### Audit universe and risk-based planning

`Update Programs` users maintain the universe through `POST /api/auditable-entities` and `PUT /api/auditable-entities/{entity}`. Required entity fields are `code`, `name`, `entity_type`, `owner_id`, `criticality`, `status`, `assessment_frequency`, `next_assessment_at`, and one to 100 distinct `risk_ids`; `description` and up to 250 distinct `control_ids` are optional.

The assigned owner or an `Update Programs` user assesses an active entity through `POST /api/auditable-entities/{entity}/assessments`. Payloads require inherent/residual likelihood and impact from 1–5, rationale, and a future `next_assessment_at` in `Y-m-d` format. The server derives both 1–25 scores, priority band, version, material entity/risk/control snapshots, actor/time, and fingerprint. Clients may not supply those fields. `GET /api/auditable-entities` and `GET /api/auditable-entities/{entity}/assessments` provide scoped paginated current state and complete history.

Create a draft plan with `POST /api/audit-plans` using `plan_year`, `name`, `objective`, and `manager_id`. The plan manager or an `Update Programs` user adds current assessed entities through `POST /api/audit-plans/{plan}/items`; required fields are entity/assessment IDs, item `status` (`planned`, `scheduled`, or `deferred`), planned start/end dates within the plan year, and rationale. `scheduled` requires an existing `audit_id`; the other states may optionally link one. The server derives priority rank and the item snapshot. Draft status/dates/rationale/audit link can be corrected with `PUT /api/audit-plans/{plan}/items/{item}` and a draft item removed with `DELETE` on the same route. `POST /api/audit-plans/{plan}/approve` revalidates all current assessment/governance references, approves a nonempty draft, owns approval actor/time/snapshot/fingerprint, and freezes the items. `GET /api/audit-plans` and `GET /api/audit-plans/{plan}/items` are scoped and paginated. All list page sizes are capped at 100.

A caller with `Create Audits` who is the approved plan manager or has `Update Programs` launches a planned item without an existing audit through `POST /api/audit-plan-items/{item}/launch-engagement`. Required fields are `title`, `audit_type` (`controls` or `implementations`), `manager_id`, `objective`, and `scope`; `description`, `program_id`, `exclusions`, and up to 100 distinct `team_user_ids` are optional. Status, dates, attribution, snapshots, and fingerprint are prohibited. The server uses approved item dates, includes the manager in the active team, creates a `Not Started` audit, and retains one immutable plan/item/assessment charter baseline. `GET /api/audits/{id}` returns it as `engagement_baseline`; operator audit detail and export expose the same baseline.

For an approved-plan audit in progress, its manager or an `Update Audits` user submits closeout through `POST /api/audits/{audit}/closeouts`. Required fields are `opinion`, `executive_summary`, `significant_matters`, and `recommendations_summary`; `scope_limitations` is optional. The server requires complete/concluded fieldwork and resolved data requests, owns version/actor/time/snapshots/fingerprint, and freezes the captured audit scope while review is pending. An independent `Update Audits` user reviews the latest submission through `POST /api/audit-closeout-submissions/{submission}/review` with `decision` (`approved` or `rejected`) and `review_summary`. Approval re-locks and verifies the current captured sources, retains a private PDF plus report disk/path/actual-size/SHA-256, and completes the audit; rejection permits a new submission version. `GET /api/audits/{audit}/closeouts` returns authorized paginated history capped at 100. Operator audit detail provides complete history/export and a final-report download that verifies retained size and SHA-256 before returning bytes. The workflow is bounded to 250 items, 500 requests, and 30,000 characters per captured note/detail. It does not cover legacy/manual audits, prove report truth/authenticity, or automate audit review.

Governed work-program endpoints are `GET /api/audits/{audit}/procedures`, `POST /api/audits/{audit}/procedures`, `POST /api/audit-procedures/{procedure}/execute`, and `POST /api/audit-procedure-executions/{execution}/review`. Definition requires `audit_item_id`, `code`, `title`, `objective`, `steps`, `method` (`inquiry`, `inspection`, `observation`, `reperformance`, or `analytics`), and `assigned_to`; optional fields are `population_description`, `planned_sample_size` (1–1,000,000), and an audit-period `due_at` in `Y-m-d`. Execution requires `outcome` (`effective`, `needs_improvement`, `ineffective`, or `not_applicable`) and `result`; optional fields are `exceptions`, `sample_tested` (0–1,000,000 and not above the plan), unverified `evidence_reference`, and up to 20 distinct accepted audit-file IDs in `evidence_attachment_ids`. The executor must have exact access to every selected file. The server retains retry-safe copies within 10 MiB/file and 50 MiB/request bounds and binds immutable provenance, actual size, and SHA-256 manifests into the execution fingerprint. Independent supervisory review requires `decision` (`approved` or `rework_required`) and `review_summary` (up to 30,000 characters); the executor cannot review their own work. Rework permits the manager/editor to define the next version of that code. Server-owned audit/version/status/actor/time/snapshot/fingerprint/manifest fields are prohibited. Work programs are bounded to 250 procedure versions and histories accept `page` and `per_page` (1–100). REST/operator history redacts evidence manifests and rows without exact attachment ACL; exact retained-copy download independently requires current audit view plus attachment access, and private export omits attachment-derived metadata. All executions require review and every latest code version requires approval before closeout. Procedures, evidence selection, results, and reviews remain deliberate inputs; hashes identify retained bytes but do not prove truth, sufficiency, authenticity, sampling validity, outcome, or assurance quality. Fynix does not generate procedures, select samples, inspect evidence content, prove reviewer competence, or infer outcomes.

Governed effort endpoints are `GET|POST /api/audits/{audit}/effort-budgets`, `GET|POST /api/audits/{audit}/time-entries`, `GET /api/audits/{audit}/effort-summary`, and `POST /api/audit-time-entries/{entry}/reverse`. A budget requires `user_id`, `planned_minutes` (1–600,000), and `rationale`; `audit_procedure_id` is optional and, when present, must be assigned to that user. A work entry requires `work_date` (`Y-m-d` within the audit period and not future), `minutes` (1–1,440), and `activity`; procedure, notes, and unverified source reference are optional. The authenticated team member is always the recorded user. Reversal requires a reason of at most 240 characters and optionally notes, creates one new attributable entry, and never rewrites the source. Server-owned identities, versions, types, snapshots, actors, timestamps, and fingerprints are prohibited. Histories accept `page` and `per_page` (1–100); budgets are bounded to 2,000 versions/audit, time to 10,000 entries/audit and 1,440 active minutes/person/day. Fynix does not capture time automatically, perform payroll/billing, optimize staffing, forecast capacity/utilization, or allocate resources automatically.

Governed finding endpoints are `GET|POST /api/audits/{audit}/governed-findings`, `GET /api/audit-findings/{finding}`, and `POST /api/audit-findings/{finding}/management-responses`. Finding creation requires `audit_item_id`, title, severity (`low`, `medium`, `high`, or `critical`), condition, criteria, effect, recommendation, and `accountable_owner_id`; cause is optional. The server owns the finding code, source snapshot, raiser/time, and fingerprint. The accountable owner can inspect the complete finding and response history without receiving broader audit access. Only that owner (or a super administrator) submits a response with position (`agreed`, `partially_agreed`, or `disagreed`) and narrative; action plan and `Y-m-d` target date are required for agreed/partially agreed positions and optional for disagreement. The server owns response version, finding snapshot, respondent/time, and fingerprint. History accepts `page` and `per_page` (1–100), is bounded to 500 findings/audit, 20 responses/finding, and 5,000,000 serialized evidence bytes/audit; every finding requires a response before governed closeout. Fynix does not infer findings, negotiate commitments, authenticate management assertions, or automatically create/execute remediation.

Governed remediation endpoints are `POST /api/audit-findings/{finding}/remediation` and `POST /api/audit-finding-remediations/{remediation}/follow-ups`. Handoff requires `remediation_project_id`; optional `assignee_id` and priority may override the accountable-owner/high-level defaults. The caller must manage the audit, hold `Manage Remediation`, and belong to the project. The server accepts only the latest agreed/partially agreed response, creates one linked task, carries forward its target date, and owns all snapshots, actor/time, and fingerprint fields. Follow-up requires `Update Audits`, a completed linked task, an independent reviewer, outcome (`effective`, `partially_effective`, or `ineffective`), and summary; `evidence_reference` is optional unverified text. `evidence_attachment_ids` accepts up to 20 distinct accepted audit-file IDs, and an `effective` result requires at least one. The reviewer must have exact file access; the server retains bounded copies and binds the immutable provenance/size/SHA-256 manifest into the follow-up fingerprint. A later follow-up requires changed task evidence, effective is final, and history is bounded to 20 versions. `GET /api/audit-findings/{finding}` includes handoff/task/follow-up evidence but redacts attachment manifests and rows the current reader cannot access. Exact retained-copy downloads independently recheck finding-workspace and attachment ACL. This records deliberate follow-up judgments; hashes identify retained bytes but do not authenticate management assertions, prove evidence truth/sufficiency/authenticity, infer effectiveness, or execute remediation automatically.

See `docs/AUDIT_UNIVERSE_PLANNING.md` for the priority algorithm, authorization boundaries, operator workspace, and limitations. This is deliberate ordinal planning, not organizational discovery, quantitative loss/calibrated probability, staffing optimization, or automated audit scheduling/execution.

### Audit Items

**Base URL:** `/api/audit-items`

- `GET /api/audit-items` - List all audit items
- `POST /api/audit-items` - Create a new audit item
- `GET /api/audit-items/{id}` - Get a specific audit item
- `PUT /api/audit-items/{id}` - Update an audit item
- `DELETE /api/audit-items/{id}` - Delete an audit item
- `POST /api/audit-items/{id}/restore` - Restore a soft-deleted audit item

**Searchable Fields:** `notes`

**Relations:** `audit`, `auditable`, `dataRequests`

### Programs

**Base URL:** `/api/programs`

- `GET /api/programs` - List all programs
- `POST /api/programs` - Create a new program
- `GET /api/programs/{id}` - Get a specific program
- `PUT /api/programs/{id}` - Update a program
- `DELETE /api/programs/{id}` - Delete a program
- `POST /api/programs/{id}/restore` - Restore a soft-deleted program

**Searchable Fields:** `name`, `description`

**Relations:** `programManager`, `standards`, `controls`

### Risks

**Base URL:** `/api/risks`

- `GET /api/risks` - List all risks
- `POST /api/risks` - Create a new risk
- `GET /api/risks/{id}` - Get a specific risk
- `PUT /api/risks/{id}` - Update a risk
- `DELETE /api/risks/{id}` - Delete a risk
- `POST /api/risks/{id}/restore` - Restore a soft-deleted risk

**Searchable Fields:** `code`, `name`, `description`

**Sortable Fields:** `id`, `code`, `name`, `domain`, `status`, `inherent_risk`, `residual_risk`, `created_at`, `updated_at`

Create requests require `code`, `name`, `domain`, and inherent/residual likelihood and impact values from 1–5. `domain` is one of `enterprise`, `operational`, `technology`, or `third_party`. The server derives both risk scores from their likelihood and impact inputs.

```json
{
  "code": "ERM-001",
  "name": "Strategic concentration risk",
  "description": "Critical revenue depends on a single market.",
  "domain": "enterprise",
  "status": "Not Assessed",
  "inherent_likelihood": 4,
  "inherent_impact": 5,
  "residual_likelihood": 3,
  "residual_impact": 4
}
```

**Relations:** `implementations`

### Policy Compliance

Policy detail responses include `obligations` with their accountable owner, related control, derived `compliance_status`, and latest attestation.

#### Governed policy revisions

Policy owners and users with `Update Policies` submit the current policy and mapping state through `POST /api/policies/{policy}/revisions` with `change_summary` and a canonical `proposed_effective_date` (`Y-m-d`). The server owns the policy ID, version, pending status, complete policy/risk/control/implementation snapshot, submitter/time, and SHA-256 fingerprint. Only one revision may remain pending.

A different user with `Update Policies` reviews the latest pending revision through `POST /api/policy-revisions/{revision}/review` with `decision` (`approved` or `rejected`) and `review_summary`. Review rejects stale content or mappings. Approval applies the proposed effective date; both decisions retain an immutable review snapshot, reviewer/time, and fingerprint.

`GET /api/policies/{policy}/revisions?page=1&per_page=50` returns complete newest-first history and is capped at 100 records per page. Policy owners and `Read Policies`/`Update Policies` users may read the scoped history. The operator relation provides inspection and private scoped export. These are attributable human decisions, not legal review, document authentication, qualified electronic signatures, automatic generation/distribution, or compliance inference.

#### Governed policy exceptions

Policy owners and authenticated users with `Read Policies` or `Update Policies` submit `POST /api/policies/{policy}/exception-requests` with `name`, optional `description`, required `justification`, `risk_assessment`, `compensating_controls`, a canonical `effective_date` on or after today, later `expiration_date`, and optional `review_frequency_days` (1–365, default 90). The server owns policy/status/requester/date/time, the policy and current approved-revision context snapshot, and its SHA-256 fingerprint.

A different `Update Policies` user posts `decision` (`approved` or `denied`) and `decision_summary` to `POST /api/policy-exceptions/{exception}/decisions`. Approval requires the submission-time policy context to remain current. For an approved exception, the same endpoint accepts a later `revoked` decision. Each allowed transition appends an immutable versioned decision snapshot, actor/time, and fingerprint; caller-supplied server fields are rejected.

Approval schedules monitoring at the requested frequency, capped by expiration. A user with `Update Policies` who is different from the requester and latest decision maker posts `outcome` (`effective`, `needs_action`, or `revoke_recommended`), `review_summary`, `control_effectiveness`, optional unverified `evidence_reference`, and optional `evidence_attachment_ids` (one to 20 distinct accepted audit attachments currently accessible to the reviewer) to `POST /api/policy-exceptions/{exception}/monitoring-reviews`. The server rejects `effective` when the approved policy context has changed; retains bounded file copies; and owns the immutable exception/decision/context snapshot, version, actor/time, next review date, evidence provenance/metadata/actual-size/disk/path/SHA-256 manifest, and fingerprint. The response returns authorized retained evidence under `data.evidence`. Hashes prove retained-byte identity, not truth, sufficiency, authenticity, or that the monitoring judgment was derived from the file. `needs_action` and `revoke_recommended` return an owner-attributed `issue` registered in the shared lifecycle; its REST source alias is `policy_exception`. `GET /api/policy-exceptions/{exception}/monitoring-reviews?page=1&per_page=50` returns newest-first history, capped at 100 records per page.

Approved governed exceptions remain active through the configured `expiration_date`. At 00:10 on the following day, the scheduled `fynix:reconcile-policy-exceptions` command idempotently changes them to `expired` and appends one immutable prior-state/effective-expiry/reconciliation-run/current-context/latest-monitoring/fingerprint record. Open monitoring issues continue to derive `action_required`; reconciliation does not close them. The command may also be invoked manually for recovery.

`GET /api/policies/{policy}/exception-requests?page=1&per_page=50` returns governed history, including expiry reconciliation evidence, capped at 100 records per page. The operator relation exposes governed and visibly labeled legacy history; exact monitoring-evidence downloads require current policy-workspace and attachment ACL; private export retains request/decision/monitoring/expiry snapshots but omits attachment-derived metadata. Legacy rows cannot enter the governed decision, monitoring, or server-expiry service. These records do not prove legal approval, risk quantification, evidence-reference or file authenticity/sufficiency, compensating-control effectiveness, qualified signatures, legal expiry judgments beyond deterministic enforcement of the configured date, automatic evidence collection, or remediation execution.

#### Create a policy obligation

`POST /api/policies/{policy}/obligations`

Required fields: `code`, `title`, `owner_id`, `frequency`, and `next_due_at`. Optional fields: `description`, `control_id`, and `is_active`. Frequencies are `one_time`, `monthly`, `quarterly`, `semi_annual`, or `annual`.

#### Submit an attestation

`POST /api/policy-obligations/{obligation}/attest`

```json
{
  "outcome": "compliant",
  "statement": "All privileged accounts were reviewed.",
  "evidence_reference": "EVIDENCE-2026-Q3-ACCESS",
  "evidence_attachment_ids": [42],
  "policy_exception_id": null
}
```

Outcomes are `compliant`, `non_compliant`, or `not_applicable`. The evidence reference is optional operator-supplied text; Fynix does not verify the referenced record or grant access to it. `evidence_attachment_ids` optionally accepts up to 20 distinct accepted data-request attachments downloadable by the attestor. The server retains copies bounded to 10 MiB per file and 50 MiB per attestation and returns append-only provenance, size, disk/path, and SHA-256 snapshots under `data.evidence`; hashes prove byte identity rather than truth, sufficiency, authenticity, or outcome inference. The optional exception must belong to the obligation's policy and be approved and currently in effect. Product interfaces append history rather than modifying attestations, and recurring obligations calculate their next due date from the attestation timestamp. Inactive obligations cannot be attested.

#### Policy acknowledgement campaigns

Policy owners and users with `Update Policies` launch a campaign with `POST /api/policies/{policy}/acknowledgement-campaigns`. Payloads require `title`, a future `due_at`, and `audience_user_ids` containing one to 500 distinct active user IDs; `instructions` is optional. An optional `knowledge_check` contains `passing_percentage` (1–100), `max_attempts` (1–5), and one to 20 questions with a distinct lowercase `code`, prompt, two to six distinct options, and zero-based `correct_option`. The policy must be currently effective, non-retired, and have readable embedded body content. The server allocates the campaign version and snapshots policy content, context, revision history, update time, and separate policy/comprehension SHA-256 fingerprints. Launch atomically inserts one in-app database notification per assignment and retains immutable recipient/campaign snapshots, notification ID, attempted/delivered times, and fingerprint. Any channel cancellation/error rolls back the campaign; later deletion of the bell notification does not remove delivery evidence.

The scheduled `fynix:reconcile-policy-acknowledgement-reminders` command runs daily at 08:00. For each open, unacknowledged assignment with an active recipient, it inserts at most one `due_soon` reminder when due within three days and one `overdue` reminder after the due time. Each successful database insertion retains immutable type, recipient/campaign snapshots, notification ID, attempted/delivered times, and fingerprint. Cancellation/error rolls back that reminder for later retry; acknowledged, closed, and deactivated-recipient assignments are skipped. The command may also be invoked manually.

The scheduled `fynix:reconcile-policy-acknowledgement-escalations` command runs daily at 08:10. Once an open assignment remains unacknowledged for more than three days after its due time, it inserts one in-app escalation to the current active policy owner. Immutable evidence retains the assigned user, escalation recipient, campaign/policy fingerprint, notification ID, attempted/delivered times, channel, and fingerprint. A failed insertion rolls back for retry. This is policy-owner escalation, not an inferred HR or line-management hierarchy.

Authenticated users list only their assignments, safe comprehension questions, attempts, initial delivery, reminder, and escalation evidence through `GET /api/policy-acknowledgements/mine?page=1&per_page=50`. For a configured check, only the assigned user submits an exact question-code to zero-based-option map through `POST /api/policy-acknowledgement-assignments/{assignment}/knowledge-check-attempts`; the server derives the integer percentage, pass state, version, complete scoring snapshot, actor/time, and fingerprint. Attempts are bounded by the campaign and stop after a pass. Correct answers remain hidden from employee responses but are included in the authorized manager report and private export. A pass is required before acknowledgement through `POST /api/policy-acknowledgement-assignments/{assignment}/acknowledge` with `{"acknowledged": true}` plus optional `comment` and `client_reference`. The server owns the acknowledgement statement, actor, time, and snapshots. Policy owners/editors use `GET /api/policy-acknowledgement-campaigns/{campaign}/report` for paginated assignment/check/delivery/reminder/escalation status and `POST /api/policy-acknowledgement-campaigns/{campaign}/close` for one governed closure. List page size is capped at 100. Acknowledgements remain accepted after the due time until closure; closure prevents later submissions. Scores only compare submitted indexes with a deliberately configured answer key; they do not prove training delivery, learning, identity, or compliance. Notification evidence proves insertion into Fynix's database store, not human viewing/readership or email/SMS/push receipt. Policy-owner escalation does not prove or infer an HR/line-management hierarchy.

#### Regulatory change inventory

Users with `Update Policies` create sources with `POST /api/regulatory-sources`; source owners and policy editors update them through `PUT /api/regulatory-sources/{source}` and create requirements through `POST /api/regulatory-sources/{source}/requirements`. The initial requirement payload combines `code` and `owner_id` with a first version: `change_type=new_requirement`, status, title, requirement text, effective/optional expiry dates, and up to 100 active `policy_ids` plus 250 active `control_ids`.

Source owners, requirement owners, and policy editors publish later versions through `POST /api/regulatory-requirements/{requirement}/versions`. Later change types are `amendment`, `guidance`, or `repeal`; a repeal must use `repealed` status. The server allocates version numbers and owns the source/mapping snapshot, publisher/time, and SHA-256 content fingerprint.

Assess only the current version through `POST /api/regulatory-requirement-versions/{version}/assessments`. Applicability is `applicable`, `not_applicable`, or `under_review`; impact is `low`, `medium`, `high`, or `critical`. Summary and rationale are required. `action_owner_id` and current-or-future `action_due_at` must appear together, are required for under-review and high/critical applicable assessments, and are prohibited for not-applicable assessments. The server allocates assessment versions and snapshots the exact requirement, source, mapped policies, and controls.

`GET /api/regulatory-requirements?page=1&per_page=50` returns paginated current state. `GET /api/regulatory-requirements/{requirement}/versions` returns complete version history and `GET /api/regulatory-requirements/{requirement}/assessments` returns complete assessment history. All three reads are capped at 100 records per page. `Read Policies` and `Update Policies` users see all requirements; other authenticated source/requirement owners see only assigned records. Source/version/assessment maintenance is REST-only; the operator workspace provides read-only inspection and scoped assessment export. This is manual regulatory inventory and attributable assessment—not external-feed ingestion, legal advice, source authentication, automatic applicability/mapping, or automatic remediation.

### Vendors

**Base URL:** `/api/vendors`

- `GET /api/vendors` - List all vendors
- `POST /api/vendors` - Create a new vendor
- `GET /api/vendors/{id}` - Get a specific vendor
- `PUT /api/vendors/{id}` - Update a vendor
- `DELETE /api/vendors/{id}` - Delete a vendor
- `POST /api/vendors/{id}/restore` - Restore a soft-deleted vendor

**Searchable Fields:** `name`, `description`, `contact_name`, `contact_email`

### Applications

**Base URL:** `/api/applications`

- `GET /api/applications` - List all applications
- `POST /api/applications` - Create a new application
- `GET /api/applications/{id}` - Get a specific application
- `PUT /api/applications/{id}` - Update an application
- `DELETE /api/applications/{id}` - Delete an application
- `POST /api/applications/{id}/restore` - Restore a soft-deleted application

**Searchable Fields:** `name`, `description`, `vendor.name`

**Relations:** `vendor`, `applicationOwner`

### Assets

**Base URL:** `/api/assets`

- `GET /api/assets` - List all assets
- `POST /api/assets` - Create a new asset
- `GET /api/assets/{id}` - Get a specific asset
- `PUT /api/assets/{id}` - Update an asset
- `DELETE /api/assets/{id}` - Delete an asset
- `POST /api/assets/{id}/restore` - Restore a soft-deleted asset

**Searchable Fields:** `name`, `description`, `asset_tag`

**Relations:** `assetOwner`, `implementations`

### Data Requests

**Base URL:** `/api/data-requests`

- `GET /api/data-requests` - List all data requests
- `POST /api/data-requests` - Create a new data request
- `GET /api/data-requests/{id}` - Get a specific data request
- `PUT /api/data-requests/{id}` - Update a data request
- `DELETE /api/data-requests/{id}` - Delete a data request

**Searchable Fields:** `request_text`

**Relations:** `audit`, `auditItem`, `responses`

### Data Request Responses

**Base URL:** `/api/data-request-responses`

- `GET /api/data-request-responses` - List all data request responses
- `POST /api/data-request-responses` - Create a new data request response
- `GET /api/data-request-responses/{id}` - Get a specific data request response
- `PUT /api/data-request-responses/{id}` - Update a data request response
- `DELETE /api/data-request-responses/{id}` - Delete a data request response

**Searchable Fields:** `response_text`

**Relations:** `dataRequest`, `requestee`

### File Attachments

**Base URL:** `/api/file-attachments`

- `GET /api/file-attachments` - List all file attachments
- `POST /api/file-attachments` - Create a new file attachment
- `GET /api/file-attachments/{id}` - Get a specific file attachment
- `PUT /api/file-attachments/{id}` - Update a file attachment
- `DELETE /api/file-attachments/{id}` - Delete a file attachment

**Searchable Fields:** `filename`, `original_filename`

## Response Formats

### Success Responses

**Index (List) Response:**
```json
{
  "current_page": 1,
  "data": [...],
  "first_page_url": "http://example.com/api/resources?page=1",
  "from": 1,
  "last_page": 5,
  "last_page_url": "http://example.com/api/resources?page=5",
  "next_page_url": "http://example.com/api/resources?page=2",
  "path": "http://example.com/api/resources",
  "per_page": 15,
  "prev_page_url": null,
  "to": 15,
  "total": 75
}
```

**Show/Create/Update Response:**
```json
{
  "data": {
    "id": 1,
    "title": "Example Resource",
    ...
  }
}
```

**Delete Response:**
- Status: `204 No Content`
- Body: Empty

### Error Responses

**401 Unauthorized:**
```json
{
  "message": "Unauthenticated."
}
```

**403 Forbidden:**
```json
{
  "message": "This action is unauthorized."
}
```

**404 Not Found:**
```json
{
  "message": "No query results for model..."
}
```

**422 Validation Error:**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "field_name": [
      "The field name is required."
    ]
  }
}
```

**429 Rate Limit Exceeded:**
```json
{
  "message": "Too Many Attempts."
}
```

## Example Usage

### Create a New User

```bash
curl -X POST "https://your-domain.com/api/users" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Alice Johnson",
    "email": "alice@example.com",
    "password": "SecurePassword123!",
    "password_confirmation": "SecurePassword123!",
    "roles": ["Security Admin"]
  }'
```

### List Users with Roles

```bash
curl -X GET "https://your-domain.com/api/users?with=roles" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### List Controls with Search and Pagination

```bash
curl -X GET "https://your-domain.com/api/controls?search=encryption&per_page=10&page=1" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Define and Execute a Control Test

Create a recurring boolean or numeric threshold definition. The caller needs `Update Controls`; an optional `implementation_id` must already be mapped to the control.

```bash
curl -X POST "https://your-domain.com/api/controls/7/test-definitions" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "CCT-MFA-01",
    "name": "MFA coverage",
    "owner_id": 4,
    "metric_type": "numeric",
    "operator": "greater_than_or_equal",
    "expected_value": "98",
    "frequency": "monthly",
    "next_run_at": "2026-08-31T12:00:00Z"
  }'
```

Record an observation. `outcome` is prohibited because the server derives pass/fail from the stored definition. `evidence_attachment_ids` optionally accepts up to 20 distinct file-attachment IDs. Each must belong to an accepted data-request response and be downloadable by the caller. The server retains bounded content copies and immutable provenance, size, disk/path, and SHA-256 snapshots; the response includes them under `data.evidence`. `evidence_reference` remains optional unverified external text.

```bash
curl -X POST "https://your-domain.com/api/control-test-definitions/12/execute" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "observed_value": "99.2",
    "notes": "Identity provider coverage export reviewed.",
    "evidence_attachment_ids": [81],
    "evidence_reference": "IDP-MFA-2026-08"
  }'
```

Supported `metric_type` values are `boolean` and `numeric`. Supported operators are `equals`, `not_equals`, `greater_than`, `greater_than_or_equal`, `less_than`, and `less_than_or_equal`; boolean tests use only the equality operators. Frequencies are `one_time`, `monthly`, `quarterly`, `semi_annual`, and `annual`.
Numeric values use decimal-safe comparison and accept up to 15 integer digits and 6 decimal places; exponential notation is rejected. A completed `one_time` definition cannot be executed again.
Governed evidence is limited to 10 MiB per file and 50 MiB per execution. Hashes establish retained-byte identity, not truth, sufficiency, or authenticity, and the observation value remains operator/integration supplied.

### Operational Resilience

The resilience endpoints require `MODULE_RESILIENCE_ENABLED=true` and `Manage Resilience`. Create the service, then add an impact analysis, dependencies, recovery plan, and exercises through their nested routes. Complete an exercise with measured recovery values:

```bash
curl -X POST "https://your-domain.com/api/recovery-exercises/9/complete" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "actual_recovery_time_minutes": 90,
    "actual_recovery_point_minutes": 10,
    "observations": "Standby processing and settlement validation succeeded.",
    "evidence_reference": "EXERCISE-PAY-2026-08"
  }'
```

The server prohibits caller-supplied `outcome`, RTO, and RPO snapshots. It uses the latest approved impact analysis, derives the result, snapshots both objectives, and opens an issue when an objective is missed. Exercise completion optionally accepts `evidence_attachment_ids`, an array of up to 20 distinct accepted data-request attachments downloadable by the completer. The server retains copies bounded to 10 MiB per file and 50 MiB per completion and returns append-only provenance, size, disk/path, and SHA-256 snapshots under `data.evidence`; hashes prove byte identity rather than truth, sufficiency, authenticity, or result inference. The evidence reference remains unverified external text. See `docs/OPERATIONAL_RESILIENCE.md` for every route and limitation.

For an actual disruption, `POST /api/recovery-plans/{plan}/continuity-activations` activates an approved plan with a deliberate disruption summary, business impact, and start time. `POST /api/continuity-activations/{activation}/events` advances the forward-only `activated` → `recovering` → `restored` → `closed` lifecycle; `cancelled` is terminal before restoration. Restoration requires `actual_recovery_point_minutes`; the server derives elapsed recovery time and an RTO/RPO outcome from retained objectives. Scoped `GET /api/business-services/{service}/continuity-activations` and `GET /api/continuity-activations/{activation}` expose complete immutable event history. These records evidence operator decisions, not detected downtime, executed recovery procedures, service availability, or recovery assurance.

### AI Governance

AI governance endpoints require `Manage AI Governance`. The creation sequence is:

- `POST /api/ai-systems`
- `POST /api/ai-systems/{system}/use-cases`
- `POST /api/ai-use-cases/{useCase}/assessments`
- `POST /api/ai-use-cases/{useCase}/controls` with `control_id`
- `POST /api/ai-use-cases/{useCase}/risks` with `risk_id`
- `POST /api/ai-use-cases/{useCase}/decisions`
- `POST /api/ai-use-cases/{useCase}/monitoring-reviews`

Assessment likelihood and impact fields accept integers from 1 through 5. Risk categories are `fairness`, `privacy`, `security`, `safety`, `transparency`, `accountability`, `human_rights`, and `regulatory`. The server allocates the version and derives both scores; clients may not supply those fields.

Approval requires the current assessment and at least one mapped existing control and risk. Decision values are `approved`, `rejected`, `changes_required`, and `suspended`. An approval also requires future `expires_at` and `next_monitoring_at` dates. Approval and monitoring snapshot creation serializes with use-case, system, and mapping changes. Current governance values that differ from the approval snapshot require a new approval; reverting mutable inventory values to that snapshot restores the derived approval state. Monitoring outcomes are `satisfactory`, `needs_action`, and `suspended`. Monitoring reviews optionally accept `evidence_attachment_ids`, an array of up to 20 distinct accepted data-request attachments downloadable by the reviewer. The server retains bounded content copies and returns immutable provenance, size, disk/path, and SHA-256 snapshots under `data.evidence`; hashes prove byte identity rather than truth, sufficiency, or authenticity. `evidence_reference` remains unverified external text. See `docs/AI_GOVERNANCE.md` for the workflow and limitations.

### Third-Party Risk Management

Third-party risk mutation endpoints require `Manage Third Party Risk` unless an endpoint below explicitly permits the assigned requirement/indicator/business owner. Read-only histories use the engagement/vendor workspace scope (`Manage Third Party Risk`, `Read Vendors`, or the assigned vendor manager):

- `POST /api/vendors/{vendor}/risk-assessments`
- `POST /api/vendors/{vendor}/risks` with `risk_id`
- `POST /api/vendors/{vendor}/risk-decisions`
- `POST /api/vendors/{vendor}/risk-reviews`
- `POST /api/vendors/{vendor}/fourth-party-dependencies`
- `POST /api/vendors/{vendor}/engagements`
- `POST /api/third-party-engagements/{engagement}/events`
- `POST /api/third-party-engagements/{engagement}/contract-risk-reviews`
- `POST /api/third-party-engagements/{engagement}/due-diligence-reviews`
- `GET|POST /api/third-party-engagements/{engagement}/onboarding-requirements`
- `POST /api/third-party-engagement-onboarding-requirements/{requirement}/complete`
- `GET|POST /api/third-party-engagements/{engagement}/onboarding-readiness-reviews`
- `GET|POST /api/third-party-engagements/{engagement}/monitoring-indicators`
- `GET|POST /api/third-party-engagement-monitoring-indicators/{indicator}/observations`
- `GET|POST /api/third-party-engagements/{engagement}/collaboration-requests`
- `POST /api/third-party-engagement-collaboration-requests/{collaborationRequest}/decisions`
- `POST /api/third-party-engagement-collaboration-requests/{collaborationRequest}/reassign`
- `POST /api/third-party-engagement-collaboration-requests/{collaborationRequest}/cancel`
- `POST /api/third-party-engagement-collaboration-requests/{collaborationRequest}/close`
- `POST /api/third-party-collaboration-extensions/{extension}/decision`
- `POST /api/third-party-engagement-collaboration-escalations/{escalation}/acknowledge`
- `POST /api/third-party-engagement-collaboration-escalations/{escalation}/resolve`
- `POST /api/third-party-engagement-collaboration-escalations/{escalation}/issues`

Dependency history is readable through `GET /api/vendors/{vendor}/fourth-party-dependencies` by third-party risk managers, users with `Read Vendors`, and the assigned vendor manager. Cross-vendor concentration is readable through `GET /api/third-party-risk/fourth-party-concentrations` only by third-party risk managers and users with `Read Vendors`. Both reads accept `page` and `per_page` with a maximum page size of 100.

Engagement history is readable by the same scoped vendor readers through `GET /api/vendors/{vendor}/engagements`, `GET /api/third-party-engagements/{engagement}`, `GET /api/third-party-engagements/{engagement}/events`, `GET /api/third-party-engagements/{engagement}/due-diligence-reviews`, and `GET /api/third-party-engagements/{engagement}/contract-risk-reviews`; list/event/review history accepts `page` and `per_page` up to 100. A proposal requires code, name, service description, active business owner, criticality, data-access declaration, term dates, and an in-term next-review date. Lifecycle values are `proposed`, `due_diligence`, `approved`, `active`, `renewal_review`, `exited`, and `rejected`. Approval requires the latest exact current vendor-risk assessment/approval plus the latest satisfactory or conditional structured due-diligence review, and an approver separated from the proposer, assessor, risk decision maker, and due-diligence reviewer. The review requires a completed scored same-vendor assessment survey, five one-to-five ratings, findings, rationale, decision, and in-term next-review date; conditional decisions require conditions. Up to 20 current approved vendor documents may be selected. The server owns bounded survey/document/engagement/risk snapshots, source-event fingerprint, version, actor/time, and fingerprint; source snapshots are returned only under their current exact record ACL.

Initial and renewed activation also require the latest contract-risk decision (`approved` or `conditionally_approved`) to bind the exact current engagement event and vendor-risk approval and cover the applicable term. The contract reviewer must differ from the engagement proposer, approver, business owner, current assessor, and risk-decision actor. Reviews require canonical `Y-m-d` effective/expiration dates, an agreement type (`master_service`, `statement_of_work`, `data_processing`, `software_license`, or `other`), seven governed risk-term booleans, service-level/liability/exit summaries, and rationale; conditional approval requires conditions and unqualified approval requires every term indicator. Changed context requires due-diligence reapproval and a new review. Renewal requires an extended term and newly current risk and contract reviews. Exit requires a summary and data-disposition statement. The server owns lifecycle state, snapshots, attribution, versions, timestamps, and fingerprints. These records evidence deliberate internal judgments; they do not author, negotiate, sign, store, transmit, or execute contracts, perform procurement/onboarding/offboarding, validate delivery, or prove data deletion or legal effect.

Initial activation additionally requires governed onboarding readiness. Managers define controls with category (`security`, `privacy`, `resilience`, `compliance`, `access`, `operational`, `financial`, or `other`), title, acceptance criteria, active owner, current in-term due date, and required flag. The owner or manager appends completion summary and optional unverified source reference. A manager separated from engagement, contract, control-owner, and completion actors records `ready`, `ready_with_conditions`, or `not_ready`, summary, optional conditions, and an in-term next-review date against the complete current controls and latest completions. Every required control must have completion evidence; conditional readiness requires conditions; activation requires the latest current accepted review and a different actor. Histories are scoped and paginated up to 100 records per page. These records evidence deliberate internal configuration and review, not technical execution, source-reference authenticity, successful access provisioning, provider fitness, or onboarding effectiveness.

Active-engagement monitoring definitions require `Manage Third Party Risk`, a current accepted contract review, and the exact current vendor-risk approval. Definition payloads require code/name, category (`service_level`, `availability`, `security`, `privacy`, `compliance`, `financial`, `concentration`, or `other`), unit, `higher_is_worse` or `lower_is_worse`, fixed-decimal warning/critical thresholds, cadence from one to 366 days, active owner, and measurement method. Posting the same code appends a new immutable version; only its latest version can receive observations. Observations require a fixed-decimal value and nonfuture time, with optional notes/source reference, and are accepted from a manager, indicator owner, or engagement business owner. Lists accept `page` and `per_page` up to 100. The server derives threshold and due/action display state and owns all snapshots, reasons, attribution, versions, times, and fingerprints. Values and sources are deliberate inputs; these routes do not ingest telemetry, authenticate reports, calculate contractual credits, infer provider performance/compliance, or automate remediation.

Assessments accept 1–5 likelihood, impact, residual likelihood, and residual impact values. Categories are `cybersecurity`, `privacy`, `operational`, `financial`, `concentration`, `geographic`, `compliance`, `reputational`, and `subcontractor`. An optional `survey_id` must identify a completed `vendor_assessment` survey for the same vendor with a score and scoring timestamp. The server allocates versions, derives both scores, and snapshots the survey score; clients cannot supply derived fields.

Only existing risks classified as `third_party` may be linked. Decisions are `approved`, `conditionally_approved`, `rejected`, and `terminated`. Approval requires an assessment, at least one linked risk, and future expiration/review dates. Reviews require a current approval; outcomes are `satisfactory`, `needs_action`, and `terminate`. `evidence_attachment_ids` optionally accepts up to 20 distinct accepted data-request attachments downloadable by the reviewer. The server retains copies bounded to 10 MiB per file and 50 MiB per review and returns append-only provenance, size, disk/path, and SHA-256 snapshots under `data.evidence`; hashes prove byte identity rather than truth, sufficiency, authenticity, or review inference. Evidence references remain unverified external text. See `docs/THIRD_PARTY_RISK_MANAGEMENT.md` for workflow details and limitations.

A fourth-party declaration requires exactly one known `fourth_party_vendor_id` or external `fourth_party_name`, `status` (`active` or `exited`), `category` (`cloud_infrastructure`, `data_processing`, `technology_service`, `financial_service`, `logistics`, `professional_service`, or `other`), `criticality` (`low`, `medium`, `high`, or `critical`), `service_description`, and `rationale`. `business_service_id`, `data_access`, and `source_reference` are optional. The server normalizes identity, allocates the per-primary-vendor/dependency version, records the actor/time, and snapshots material vendor/service/dependency values. History and concentration groups default to 50 records per page. Current concentration uses only the latest version for each primary-vendor/dependency pair; an `exited` latest version removes that pair. Bands are `limited` for one non-high dependency, `moderate` for two to four primary vendors or at least one high/critical dependency, and `high` for at least five primary vendors or at least three high/critical dependencies. These are deterministic inventory roll-ups, not externally verified corporate ownership, continuous monitoring, or quantitative loss concentration.

Exit additionally requires governed offboarding readiness. Managers define controls through `POST /api/third-party-engagements/{engagement}/offboarding-requirements`; assigned owners or managers append completion versions through `POST /api/third-party-engagement-offboarding-requirements/{requirement}/complete`; and independent managers record `ready`, `ready_with_conditions`, or `not_ready` through `POST /api/third-party-engagements/{engagement}/offboarding-readiness-reviews`. The matching requirement and review histories are scoped paginated `GET` endpoints. Required controls need completion evidence, conditional readiness needs conditions, and the exit actor must differ from the current readiness reviewer. Statements and references are deliberate and unverified; Fynix does not revoke access, retrieve assets, delete data, execute transition work, or prove offboarding effectiveness.

Governed collaboration is available during due diligence, approval, active service, and renewal review. A manager opens a request with category (`assurance`, `evidence`, `risk`, `contract`, `incident`, `resilience`, `onboarding`, `offboarding`, or `other`), subject, request text, exact activated same-vendor portal contact, and current/future due date. Only that authenticated portal recipient can respond through the vendor workspace, with required response text, optional unverified source reference, and optional `vendor_document_ids` containing up to 20 distinct same-vendor documents currently visible to that recipient. The server retains copies bounded to 10 MiB per file and 50 MiB per response and binds immutable source identity/status, actual size, retained disk/path, SHA-256, actor/time, and manifest into the event fingerprint. Staff REST/operator metadata is filtered by current source-document ACL; exact staff and portal downloads independently require current collaboration-workspace plus source-document access. A manager other than the opener may record `accepted` or `follow_up` with a summary. The same recipient may answer follow-up. Staff history is paginated up to 100; each engagement is capped at 100 requests and each request at 20 immutable events.

An accepted response may receive one administrative closure from a third-party risk manager separated from the opener and acceptance actor. Any escalation must already be resolved. The closure retains the complete request, exact acceptance decision and accepted response including its hidden evidence manifest, current recipient/effective-due, optional escalation, actor/time, summary, and SHA-256 evidence. Staff history exposes the complete record; the exact-recipient portal exposes only summary/time/fingerprint. Closure does not prove fulfillment, response truth, performance, external completion, or contractual discharge.

Closure derives `response_recorded_at`, `timeliness_status` (`on_time` or `late`), `days_late`, the retained `calendar_timezone` (`UTC`), and a separately reconstructible `timeliness_fingerprint` from the accepted response and exact effective due context. The full UTC due date is inclusive; lateness begins on the following UTC calendar day and approved extensions supply the effective boundary. Backfilled closures retain their `closure/v1` primary fingerprint formula while receiving separately reconstructible timeliness evidence; new `closure/v2` fingerprints bind the timeliness fields into the primary closure fingerprint. This is in-product date comparison, not contractual SLA interpretation, service-credit calculation, or provider-performance assurance.

For each new closure, the server atomically inserts one exact-recipient database notification and an immutable `delivery` record containing notification identity, channel, material recipient identity/account-state and complete closure snapshots, attempted/delivered times, and a recursively canonicalized fingerprint. The delivery ledger survives bell-notification deletion. Staff history receives the full record with nested retained-document metadata filtered by current source-document ACL. The provider projection contains only notification identity, channel, times, and fingerprint; it omits record/containment identifiers plus recipient and closure snapshots. Database insertion is not evidence of human reading or external delivery.

The exact active delivered recipient may use the provider workspace to acknowledge closure once. The immutable `acknowledgement` binds the material recipient identity/account state and complete closure/delivery snapshots with time and fingerprint. In the same transaction the server inserts one database notification for each distinct current active engagement business owner/vendor relationship manager and retains deduplicated roles, recipient/acknowledgement snapshots, notification identity, attempted/delivered times, channel, and fingerprint. Cancelled delivery rolls back the acknowledgement and every delivery record. Staff history receives the full ACL-filtered evidence; the provider projection contains only acknowledgement time and fingerprint and no internal delivery relation. There is deliberately no public provider acknowledgement endpoint. These actions are not evidence of reading, comprehension, agreement, contractual assent/discharge, response truth, fulfillment, external delivery, or performance.

The exact current active internal user named by a closure-acknowledgement delivery may call `POST /api/third-party-collaboration-closure-acknowledgement-deliveries/{delivery}/acknowledge` with no payload. One immutable receipt binds material recipient identity, the complete delivery snapshot, time, and fingerprint; a second call is rejected and database uniqueness enforces one receipt per delivery. Staff history and operator inspection expose the ACL-filtered receipt, while the provider projection omits internal delivery and receipt relations. This action proves only an authenticated in-product action—not reading, comprehension, agreement, external delivery, fulfillment, provider performance, contractual assent/discharge, or assurance.

A manager may reassign an awaiting, non-escalated request through the reassignment endpoint using an activated same-vendor `recipient_vendor_user_id` and required reason. The replacement must differ from the current recipient, and any pending due-date extension must receive a terminal decision first. At most 20 immutable reassignments retain the original request plus exact prior/replacement recipient, actor, reason, time, version, and fingerprint evidence. The original request recipient is never rewritten. Current portal scope, response/extension authority, future reminder/escalation delivery, and retained-file downloads derive from the latest reassignment; former recipients lose current request discovery and actions. Reassignment removes that recipient's mutable bell notifications for this request while preserving the immutable delivery ledger. Staff history includes complete reassignment evidence, while the current recipient portal omits internal actor/request snapshots. This does not verify provider identity, authority, availability, receipt, or response quality.

A manager may cancel an awaiting, non-escalated request once using a required reason after any pending extension receives a terminal decision. The immutable cancellation binds the complete original request, exact latest event and evidence manifest, current recipient/effective-due contexts, manager, reason, time, and SHA-256 fingerprint without rewriting earlier evidence. Cancellation removes the current recipient's mutable reminder bells and terminates response, extension, reassignment, reminder, and escalation processing. Staff history exposes complete evidence; the current exact-recipient portal exposes only the terminal reason/time/fingerprint. This records an in-product governance decision, not external cessation, legal release, or contract amendment.

The exact current portal recipient may acknowledge an awaiting request once per immutable recipient-assignment context. Reassignment creates a new acknowledgement context for the replacement without changing prior evidence. Each of at most 21 acknowledgements retains the complete request, exact latest event/evidence manifest, current recipient/effective-due contexts, recipient, time, and SHA-256 fingerprint. Staff REST/operator history exposes complete evidence and the exact-recipient portal exposes safe history. Acknowledgement proves only an authenticated in-product action, not comprehension, external delivery, authority, truth, or fulfillment.

The exact portal recipient may request up to 20 due-date extensions while the request remains awaiting and non-escalated. Each proposal requires a canonical `proposed_due_at` later than the current effective due date and no later than the engagement term, plus a reason. A `Manage Third Party Risk` actor other than the request opener decides the latest pending proposal as `approved` or `rejected` with a summary through the extension-decision endpoint. The original request date remains immutable; an approved decision establishes the fingerprinted effective due context used by reminders and escalation. Proposal and decision evidence appears in scoped staff history and exact-recipient portal history. These deliberate records do not prove provider need, contractual assent, legal amendment, response truth, or performance.

The daily `fynix:reconcile-third-party-collaboration-reminders` process inserts one `due_soon` database notification during the inclusive three-day window and one `overdue` notification only after the effective due date ends, while the latest event remains `requested` or `follow_up`. Recipient notification and immutable evidence are transactional and idempotent per request/type; extension requests close once overdue evidence exists. Embedded staff and exact-recipient histories expose the due context, type, database channel, notification identity, complete request/latest-event and recipient snapshots, attempted/delivered times, and fingerprint. New and backfilled governed evidence always contains that context; the additive columns remain nullable solely so routine rollback to the prior writer remains deployment-compatible. These records prove attributable in-product insertion and database-store delivery only; they do not prove external delivery, human reading, external identity, truth, authenticity, sufficiency, relevance, provider performance, contractual amendment, or automated collaboration. Database rollback invokes compensating retained-copy cleanup; storage-adapter and administrator failures remain outside that guarantee.

The daily `fynix:reconcile-third-party-collaboration-escalations` process escalates once after three full grace days beyond the exact effective due context when that context's governed overdue reminder exists and the latest event is still `requested` or `follow_up`. It resolves the current active engagement business owner and vendor relationship manager under the governed lock boundary, deduplicates a shared user, and transactionally inserts every internal database notification plus one immutable escalation record. Staff REST/operator history exposes the effective due context, internal recipient roles and snapshots, notification identities, exact request/latest-event/overdue-reminder snapshots, times, and fingerprint. The exact provider recipient's portal exposes only escalation occurrence, channel, delivery time, and fingerprint. This proves database-store insertion—not reading, email/external delivery, response resolution, management action, or automatic remediation.

The current business owner or vendor relationship manager acknowledges an escalation with `summary`, `action_plan`, and a canonical current/future `target_resolution_at`. A different currently accountable user or caller with `Manage Third Party Risk` resolves it with a `summary` only after a later collaboration event is independently accepted; the resolver must also differ from the acceptance actor. Both writes allocate immutable versions and bind the complete escalation, actor, time, and, for resolution, accepted-event evidence into SHA-256 fingerprints. Collaboration history is also readable by the current business owner and exposes full internal action evidence; the exact provider portal exposes only lifecycle status, time, and fingerprint. Notification delivery alone never resolves an escalation. These are deliberate internal decisions, not proof of reading, response truth, plan execution, remediation, external delivery, or provider performance.

Once an acknowledged target date has ended and the latest event still awaits the provider, a caller with `Manage Third Party Risk` may post a required `rationale` to the escalation issue endpoint. Retries return the same single source issue. The server selects an active accountable owner, retains and fingerprints the exact escalation, acknowledgement, latest event, owner, opener, rationale, and time, and registers an `open` issue under source alias `third_party_collaboration` with the missed target as its lifecycle due date. Staff REST/operator history exposes the issue; the exact provider portal does not expose its existence or internal contents. Later response acceptance or escalation resolution does not imply remediation and does not close the issue. Shared remediation and independent closure use the governance-issue endpoints below.

### Enterprise, Operational, and Technology Risk Portfolios

Portfolio governance writes require `Manage Risk Portfolio`; the read endpoints below also permit `Read Risks` and, with the documented redactions, the selected root's accountable owner:

- `PUT /api/risks/{risk}/governance-profile`
- `POST /api/risks/{risk}/governance-reviews`
- `POST /api/risks/{risk}/operational-loss-events`
- `GET /api/risks/{risk}/operational-loss-events`
- `POST /api/risks/{risk}/indicators`
- `GET /api/risks/{risk}/indicators`
- `PUT /api/risk-indicators/{indicator}`
- `POST /api/risk-indicators/{indicator}/observations`
- `GET /api/risk-indicators/{indicator}/observations`
- `POST /api/risks/{risk}/technology-exposure-assessments`
- `GET /api/risks/{risk}/technology-exposure-assessments`
- `PUT /api/risks/{risk}/parent` with `parent_risk_id` (nullable)
- `GET /api/risks/{risk}/rollup`
- `POST /api/risks/{risk}/scenarios`
- `GET /api/risks/{risk}/scenarios`
- `GET /api/enterprise-risk-scenarios/{scenario}`

Profiles require `owner_id`, an `appetite_threshold` from 1–25, `review_frequency` (`monthly`, `quarterly`, `semi_annual`, or `annual`), and a future `next_review_at`. Enterprise risks also require `strategic_objective`; operational risks require an active `business_service_id`; technology risks require risk mappings to an active asset and to a fully implemented implementation linked to an applicable control.

Parent assignment requires active, governed enterprise risks and rejects self-links and cycles. Set `parent_risk_id` to `null` to detach an active or inactive governed enterprise risk as a root. Writes are serialized and retried transactionally. Current and historical hierarchy or scenario references prevent risk deletion through product interfaces. The roll-up endpoint is also readable by users with `Read Risks` and by the selected root's accountable owner. It returns current active risk/descendant counts; residual-score sum, average, and maximum; above-appetite count; score-band counts; its `current_active_residual_scores` basis; and generation time, with limits of 100 levels and 10,000 descendants. Owner-only aggregates may include other owners' descendants but do not disclose their identities or privileged change history.

Scenario creation requires `name`, `narrative`, `horizon_months` from 1–120, a qualitative `probability_band` (`rare`, `unlikely`, `possible`, `likely`, or `almost_certain`), and an `adjustments` array. Each adjustment requires a distinct active hierarchy `risk_id`, integer `likelihood_shift` and `impact_shift` from -4 through +4, and may include `rationale`; at least one shift must be non-zero. The server owns versions, timestamps, snapshots, fingerprints, and every derived score. It analyzes all active root and descendant risks, gives unspecified risks zero shift, clamps stressed values to 1–5, and persists append-only attributable scenario and item snapshots through product interfaces. Scenario history accepts `page` and `per_page` (maximum 100); detail accepts `item_page` and `item_per_page` (maximum 100). `Read Risks` users can list and inspect scenario detail. The accountable root owner can list and inspect aggregate summaries, but item snapshots and manager attribution are restricted. The `Risk` MCP tool returns at most the latest 10 compact summaries without narratives; privileged callers may pass a `scenario` selector formatted as `ID` or `ID:PAGE` to receive a separate five-item page with byte-bounded name, narrative, and rationale excerpts. The scenario portion is capped at 6,000 serialized bytes and sets `enterprise_scenario_output_truncated` when it must omit older summaries or item rows. These deterministic ordinal-score stresses support prioritization; they are not quantitative loss, correlation, capital, calibrated probability, or Monte Carlo results.

Review decisions are `accepted`, `mitigate`, `transfer`, and `avoid`. The server derives every snapshot field. A residual score above appetite cannot be accepted; treatment decisions open an issue. `evidence_attachment_ids` optionally accepts up to 20 distinct accepted data-request attachments downloadable by the reviewer. The server retains copies bounded to 10 MiB per file and 50 MiB per review and returns append-only provenance, actual size, disk/path, and SHA-256 snapshots under `data.evidence`; hashes prove retained-byte identity rather than truth, sufficiency, authenticity, or review inference. Evidence references remain unverified external text. Third-party and unclassified risks are rejected by these endpoints because they use other workflows. See `docs/RISK_PORTFOLIO_GOVERNANCE.md` for evidence boundaries and limitations.

Operational loss-event creation requires `Manage Risk Portfolio` and a governed operational risk mapped to an active business service. Payloads require `category`, past-or-current `occurred_at` and `detected_at` dates, `summary`, nonnegative decimal-string `gross_loss`, optional nonnegative decimal-string `recoveries`, and an uppercase three-letter `currency`; `source_reference` is optional. Categories are `internal_fraud`, `external_fraud`, `employment_practices`, `clients_products_business_practices`, `physical_asset_damage`, `business_disruption_system_failure`, `execution_delivery_process_management`, and `other`. Amount strings accept up to 14 whole digits and two decimal places. The server rejects recoveries above gross loss and derives `net_loss` without binary floating-point arithmetic. Event history accepts `per_page` up to 100 and is readable by portfolio managers, `Read Risks` users, and the risk's accountable owner. Events are append-only through product interfaces. They are operator-reported historical observations, not authenticated source records, converted/aggregated currency measures, statistical loss models, forecasts, or capital calculations.

Operational KRI creation requires `Manage Risk Portfolio` and a governed operational risk mapped to a business service. A definition requires `owner_id`, unique-per-risk `code`, `name`, `unit`, `direction` (`higher_is_worse` or `lower_is_worse`), decimal-string warning and critical thresholds, `frequency` (`weekly`, `monthly`, `quarterly`, or `yearly`), and `next_due_at`; description and active state are optional. Threshold and observation values accept up to 15 whole digits and six decimal places. The critical threshold must be beyond warning in the adverse direction. Portfolio managers, the indicator owner, and the risk owner can append observations; `Read Risks` users have read access. Observation payloads require `observed_value` and optionally accept a non-future `observed_at`, notes, and an unverified source reference. The server derives normal/warning/critical status with decimal-safe comparisons, snapshots the applicable unit/direction/thresholds, advances the due date, and exposes paginated definition and append-only observation history (`per_page` maximum 100). This workflow records deliberate observations; it does not ingest feeds, validate sources, infer metrics from files, aggregate samples, or provide process/control telemetry.

Technology exposure assessment creation requires `Manage Risk Portfolio`, an active governed `technology` risk, a selected currently mapped active `asset_id`, and at least one mapped fully implemented implementation linked to an applicable control. Payloads require `exposure_type` (`vulnerability`, `threat_scenario`, `misconfiguration`, `unsupported_technology`, or `other`), title, threat scenario, vulnerability description, inherent/residual likelihood and impact from 1–5, recommended response, and a current-or-future review date. Vulnerability and external source references are optional operator-supplied strings. Residual score may not exceed inherent score. The server allocates a per-risk version, derives both 1–25 scores, within/above-appetite state, and scheduled/review-overdue state, and snapshots the selected asset plus the locked risk/profile/asset/implementation/control governance graph. History is append-only through product interfaces, paginated to 100 records, readable by portfolio managers, `Read Risks` users, and the risk's accountable owner, and available in the scoped operator workspace/export; inspection exposes the complete snapshot and export includes deterministic asset/governance snapshot JSON plus its fingerprint. This workflow records deliberate assessments; it does not discover assets, threats, or vulnerabilities, validate CVE/scanner/source data, ingest telemetry, calculate CVSS, or automatically remediate exposure.

### Governance Issue and Remediation Lifecycle

Issue lifecycle endpoints use source type `risk`, `vendor`, `ai`, `resilience`, `control_test`, `policy_exception`, or `third_party_collaboration`:

- `GET /api/governance-issues/{type}/{issue}`
- `POST /api/governance-issues/{type}/{issue}/remediation`
- `POST /api/governance-issues/{type}/{issue}/request-verification`
- `POST /api/governance-issues/{type}/{issue}/close`
- `POST /api/governance-issues/{type}/{issue}/reopen`

Issue owners may read their own lifecycle. `Manage Issue Lifecycle` is required to create a remediation handoff, request verification, or reopen. Handoff requires `remediation_project_id`, `priority` (`Low`, `Medium`, `High`, or `Critical`), a current-or-future `due_date`, and `rationale`; `assignee_id` is optional. The caller must also belong to the selected remediation project, and the server creates and links the task transactionally.

Requesting verification requires a completed linked task and `rationale`. Closure requires `Verify Issue Closure`, `verification_summary`, `evidence_attachment_ids` containing one to 20 distinct file-attachment IDs, a still-completed task, and a verifier independent of the issue owner and task owner/assignee. Every attachment must belong to an accepted data-request response, be downloadable by the verifier under the private-file policy, and have readable content on the configured storage disk. Hashing and retained-copy creation are bounded while streaming to 10 MiB per file and 50 MiB total. The server snapshots attachment/response/request/audit identities, accepted state, file metadata, actual size, retained-copy disk/path, and SHA-256 under the closure transition. Evidence downloads address that exact snapshot and reauthorize the lifecycle and linked attachment. `evidence_reference` remains optional unverified external text. Reopening requires `rationale` and retains prior evidence snapshots. The only accepted sequence is `open` → `in_remediation` → `verification` → `closed`, with `closed` → `open` for regression or invalidated closure. See `docs/GOVERNANCE_ISSUE_LIFECYCLE.md` for workflow and evidence boundaries.

### Create a New Standard

```bash
curl -X POST "https://your-domain.com/api/standards" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "NIST-800-53",
    "title": "NIST Special Publication 800-53",
    "description": "Security and Privacy Controls",
    "status": "active"
  }'
```

### Get Audit with Full Details

```bash
curl -X GET "https://your-domain.com/api/audits/1?with_details=true" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Update an Implementation

```bash
curl -X PUT "https://your-domain.com/api/implementations/5" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "status": "implemented",
    "effectiveness": "effective"
  }'
```

### Delete a Risk

```bash
curl -X DELETE "https://your-domain.com/api/risks/3" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Restore a Deleted Control

```bash
curl -X POST "https://your-domain.com/api/controls/7/restore" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

## Security Best Practices

1. **Always use HTTPS** in production
2. **Keep tokens secure** - never commit them to version control
3. **Rotate tokens regularly** - generate new tokens periodically
4. **Use appropriate permissions** - follow the principle of least privilege
5. **Monitor API usage** - watch for unusual patterns or rate limit hits
6. **Validate all input** - the API performs server-side validation
7. **Handle errors gracefully** - check response status codes

## Support

For issues or questions about the API, please create an issue on the Fynix Cyber Audit GitHub repository.
## Governed cyber incidents

The incidents module provides a deliberately operated register, not automated detection or SOAR. Reads require `List Incidents`/`Read Incidents` or `Manage Incidents`; creation requires `Create Incidents` or `Manage Incidents`; phase transitions require `Update Incidents` or `Manage Incidents`.

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
- `GET|POST /api/incidents/{incident}/affected-entities`
- `GET|POST /api/incidents/{incident}/timeline`
- `GET|POST /api/incidents/{incident}/final-reports`

Task-event mutation requires `summary` and at least one status, assignee, or due-date change. It optionally accepts up to 20 distinct accepted data-request attachment IDs in `evidence_attachment_ids`; the actor must currently be able to download every selected attachment. The server retains retry-safe copies within 10 MiB/file and 50 MiB/request limits and binds immutable provenance, actual size, retained location, and SHA-256 manifests into the event fingerprint. Failure transactionally rolls back database state and invokes compensating cleanup for successfully written uncommitted copies; storage-adapter or administrator failures remain outside that guarantee. Event responses and paginated history include only evidence passing the current exact attachment ACL. Exact retained-copy download independently requires current incident/task-workspace access plus linked-attachment access. Hashes identify retained bytes but do not prove truth, sufficiency, authenticity, relevance, response execution, effectiveness, or that a decision was derived from the file.

Create with `incident_playbook_id`, `title`, `severity`, `detected_at`, and optional `type`, `involves_data`, `involves_pii`, and `is_breach`. The server owns number, status, phase, lead/reporter, playbook snapshot, and initial transition. Advance with the exact next `phase`, a required `summary`, and optionally up to 20 distinct accepted attachment IDs in `evidence_attachment_ids`. The actor must currently be able to download every selected attachment. The server retains bounded copies and binds their immutable provenance, actual size, retained location, and SHA-256 manifests into the transition fingerprint. Responses expose only evidence passing the current exact attachment ACL; downloads independently require current incident-view and source-attachment access. See `docs/INCIDENT_RESPONSE.md` for bounds and limitations.

Record a task event with a required `summary` and at least one of `status`, `assignee_id`, or `due_date`. Managers may change all three; the current assignee may change status only. The server owns event version, before/after snapshots, actor/time, and fingerprint. Incident detail exposes task state and event counts; complete history uses the bounded task-events endpoint or operator history action.

Notification decisions require `Manage Breach Notifications`; incident readers have inspection only. Registration requires `audience` (`Regulator`, `Affected Individuals`, `Partner`, `Insurer`, `Law Enforcement`, or `Other`), `recipient`, and `rationale`, with optional `framework` and `deadline_at`. The server starts `Assessment Pending`. Decisions may supply status/context plus required rationale. Allowed status progress is pending → required/not-required/cancelled, required → prepared/not-required/cancelled, and prepared → sent/cancelled. Required/prepared needs a deliberate deadline; sent needs an external `delivery_reference` and receives a server timestamp. Terminal records cannot change. Each incident is bounded to 100 records and each record to 50 immutable events. Fynix derives deadline display state and retains before/after snapshots, actor/time, and fingerprint, but it does not make legal determinations, calculate statutory deadlines, send notices, authenticate references, or prove receipt/readership.

Lessons require a governed incident already in `Lessons Learned`. Registration requires `area` (`People`, `Process`, `Technology`, `Communication`, `Vendor`, `Governance`, or `Other`), `observation`, `recommendation`, active `owner_id`, and `rationale`; `target_date` is optional and cannot precede today. The server starts `Proposed`. A manager or owner may advance to `In Progress`, then `Implemented`, or close without action; the owner may change status only. Manager-owned content/accountability corrections and every progress decision require rationale. Implemented/closed records are terminal. Each incident is bounded to 100 lessons and each lesson to 50 immutable events with complete persisted incident/lesson before-and-after snapshots, actor/time, and fingerprint. Incident readers receive complete history; an accountable owner without incident-read permission receives owner-safe history with the embedded incident snapshot redacted. Derived target state is display-only; all content and implementation state are deliberate inputs, not remediation execution or effectiveness assurance.

Affected-entity registration requires `entity_type` (`Asset`, `Application`, `Vendor`, `Control`, or `Risk`), a current `entity_id`, and `impact_summary`; controls also require `control_failure_note`. Each governed incident retains at most 100 unique type/source pairs. The server locks and snapshots material current source values, actor/time, and a SHA-256 fingerprint. Records are append-only and remain reconstructible after later source changes. Selection, impact, and failure notes are deliberate inputs rather than causal or control-effectiveness inference.

Timeline registration requires `entry_type` (`Observation`, `Action`, `Decision`, or `Communication`), `visibility` (`Internal` or `Auditor`), an `occurred_at` time no later than now, and `summary`; `details` and `pinned` are optional. The server assigns one of at most 500 versions under the incident lock and retains the complete material incident snapshot, recorder/time, and SHA-256 fingerprint. Incident editors inspect all entries; read-only incident users receive only auditor-visible entries through both REST and the operator workspace. Timeline content remains a deliberate operator statement rather than telemetry or response-execution proof.

Final-report generation requires the incident to be in `Lessons Learned`, current incident update authority, `Manage Incident Evidence`, `executive_summary`, and `conclusions`. The server assigns one of at most 20 versions and captures a point-in-time snapshot of incident state, phase decisions, current tasks/latest event fingerprints, all governed phase/task evidence up to 5,000 manifest rows, notification status, affected entities, auditor-visible timeline only, and lessons. The serialized snapshot is capped at 5,000,000 bytes and the retained PDF at 10 MiB. REST history returns report identity/hash metadata without the protected snapshot or storage path. Exact download independently requires current incident view, evidence permission, and source-attachment ACL for every included file, then verifies size and SHA-256 before streaming. A report records deliberate operator inputs and governed system history; it is not independent approval, a qualified signature, legal advice, evidence-authenticity proof, or response-effectiveness assurance.

## Governed compliance cases

The compliance-case module requires `MODULE_COMPLIANCE_CASES_ENABLED=true`. `Manage Compliance Cases` users open and govern cases; `Read Compliance Cases` users inspect all cases; `Investigate Compliance Cases` users inspect and update only their current assignments.

Authenticated active users submit internal concerns through `POST /api/compliance-case-intakes` with `title`, `category`, `priority`, `allegation`, `source_channel`, optional `source_reference`/`reporter_message`, and optional `confidential`. The server retains the exact reporter snapshot, time, reference, and fingerprint. `GET /api/my-compliance-case-intakes` returns only the caller's safe reference/title/category/priority/channel/status/times/fingerprints and omits allegation plus internal reporter, disposition, and case snapshots. `Manage Compliance Cases` users inspect the complete paginated register through `GET /api/compliance-case-intakes`; a different manager records one terminal accepted/rejected disposition through `POST /api/compliance-case-intakes/{intake}/decision`. Acceptance creates and binds the governed case and opening event atomically. This interface does not provide anonymous/public hotline or external-email intake, emergency response, identity proofing, legal advice, or anti-retaliation administration.

`GET|POST /api/compliance-case-intakes/{intake}/messages` provides bounded authenticated correspondence. The exact active reporter may append only `Reporter` messages and sees only reporter-visible history; a `Manage Compliance Cases` user may append `Reporter` or `Internal` messages and inspect complete evidence. Internal rows are filtered before reporter pagination/counting. Each intake retains at most 100 immutable messages with exact intake/current-disposition, actor, audience, version/time, and fingerprint evidence. It does not prove external delivery, reading, comprehension, response, or investigative action.

`POST /api/compliance-case-intake-messages/{message}/acknowledge` accepts no client-owned evidence fields. Only the exact current active intake reporter may acknowledge a reporter-visible message authored by another user, and each message permits one immutable receipt. The receipt binds the complete message, material reporter identity, time, and fingerprint. Reporter history exposes safe time/fingerprint status; manager REST/operator history exposes complete evidence. This proves only an authenticated in-product action—not reading, comprehension, agreement, truth, external delivery, or investigative action.

Newly governed compliance cases require an independently approved current investigation plan before `Triaged` may advance to `Investigating`. The assigned investigator or `Manage Compliance Cases` user submits `POST /api/compliance-cases/{case}/investigation-plans` with one to 20 `objectives`, required `scope`, one to 50 `procedures`, a current/future `target_completion_at`, and `rationale`. A manager separated from the author and assigned investigator records one terminal approval/rejection through `POST /api/compliance-case-investigation-plans/{plan}/review`. `GET /api/compliance-cases/{case}/investigation-plans` exposes paginated complete evidence to the existing case-view scope. At most 20 immutable versions are retained; only the latest plan bound to the exact current case event can be approved or authorize investigation. This proves deliberate planning/approval—not plan quality, legal sufficiency, execution, evidence completeness, timeliness, truth, or effective outcome.

During `Investigating` or `Action Required`, the assigned investigator or a case manager records an immutable conclusion per approved-plan procedure through `POST /api/compliance-cases/{case}/investigation-procedure-executions`. `procedure_index` (1–50), `result` (`completed`, `exception_identified`, or `not_applicable`), and `summary` are required; `findings` is required for `exception_identified`, and `source_reference` is optional. The server derives the version and exact procedure text and retains complete approved plan/review plus current case/event snapshots, executor/time, and fingerprint. A different case manager records `approved` or `rework_required` through `POST /api/compliance-case-investigation-procedure-executions/{execution}/review`, retaining the complete exact conclusion, reviewer/time, summary, and fingerprint. Rework permits a later version, bounded to 20 per procedure. `GET` on the case route exposes paginated complete conclusion/review evidence to current case readers. A newly plan-governed case cannot resolve until every latest approved-plan procedure conclusion has independent approval. These deliberate records do not prove execution, source authenticity, truth, legal sufficiency, reviewer competence, investigation quality, or effectiveness.

After every latest procedure conclusion has independent approval, the assigned investigator or a case manager submits an immutable final report through `POST /api/compliance-cases/{case}/investigation-reports` with `outcome` (`substantiated`, `partially_substantiated`, `unsubstantiated`, or `inconclusive`), `executive_summary`, `analysis`, `findings`, and `recommendations`. A case manager separated from the report author, assigned investigator, and every procedure executor/reviewer records `approved` or `rejected` through `POST /api/compliance-case-investigation-reports/{report}/review`. `GET /api/compliance-cases/{case}/investigation-reports` exposes paginated complete report/review evidence to current case readers. At most 20 immutable report versions are retained; only the latest approved report bound to the exact current case event, approved plan/review, and latest conclusion/review fingerprints permits resolution. This proves attributable report and approval decisions—not allegation truth, source authenticity/sufficiency, legal conclusions, reviewer competence, investigation quality, or effectiveness.

- `GET|POST /api/compliance-case-intakes`
- `GET /api/my-compliance-case-intakes`
- `POST /api/compliance-case-intakes/{intake}/decision`
- `GET|POST /api/compliance-case-intakes/{intake}/messages`
- `POST /api/compliance-case-intake-messages/{message}/acknowledge`
- `GET|POST /api/compliance-cases`
- `GET /api/compliance-cases/{complianceCase}`
- `GET|POST /api/compliance-cases/{complianceCase}/events`
- `GET|POST /api/compliance-cases/{case}/investigation-plans`
- `POST /api/compliance-case-investigation-plans/{plan}/review`
- `GET|POST /api/compliance-cases/{case}/investigation-procedure-executions`
- `POST /api/compliance-case-investigation-procedure-executions/{execution}/review`
- `GET /api/compliance-cases/{complianceCase}/action-issues`
- `GET|POST /api/compliance-cases/{complianceCase}/interviews`
- `POST /api/compliance-cases/{complianceCase}/interviews/{interview}/events`
- `GET|POST /api/compliance-cases/{complianceCase}/legal-holds`
- `POST /api/compliance-cases/{complianceCase}/legal-holds/{legalHold}/release`
- `GET /api/my-compliance-case-legal-holds`
- `POST /api/compliance-case-legal-holds/{legalHold}/acknowledge`

Opening requires `title`, `category`, `priority`, `allegation`, and `summary`; optional fields are `source_channel`, `source_reference`, `reporter_reference`, and `confidential`. Categories are `Conduct`, `Fraud`, `Regulatory`, `Policy Violation`, `Privacy`, `Conflict of Interest`, `Retaliation`, and `Other`. Priorities are `Low`, `Medium`, `High`, and `Critical`.

The forward lifecycle is `New` → `Triaged` → `Investigating` → `Action Required`/`Resolved` → `Closed`, with `Action Required` allowed to return to `Investigating`. Triage requires an active user with `Investigate Compliance Cases` plus a triage summary. Entering Action Required creates an immutable exact-event-bound action issue; the case cannot leave that state until the shared remediation lifecycle records completed-task state and evidence-backed independent closure. Reopening the issue is allowed only while the case remains Action Required. Resolved state requires a resolution summary and, for newly plan-governed cases, an independently approved latest conclusion for every approved-plan procedure. Final closure requires a manager separated from the opener, every assigned investigator, and every investigation/resolution decision actor; the closer must supply the closure summary in that event. Each change requires `summary` and appends a complete material before/after snapshot, actor/time, version, and fingerprint. Pages are limited to 1–100 and history to 200 events/case. Lifecycle mutations use `/api/governance-issues/compliance_case_action/{issue}`. See `docs/COMPLIANCE_CASE_MANAGEMENT.md` for privacy boundaries and limitations.

An open case accepts up to 100 evidence submissions through `POST /api/compliance-cases/{case}/evidence` from its current investigator or a manager. `summary` and one to 20 distinct `evidence_attachment_ids` are required. Each attachment must belong to an accepted audit response and pass the actor's current source ACL; retained copies use the shared 10 MiB/file and 50 MiB/submission bounds. `GET /api/compliance-cases/{case}/evidence` returns paginated immutable case/latest-event/actor/fingerprint history and only file rows passing the viewer's current source ACL; the raw manifest is never serialized. Exact retained-copy download also requires current case-workspace and source-file access. Hashes prove retained-byte identity, not evidence truth, authenticity, relevance, sufficiency, admissibility, or investigative quality.

During `Triaged`, `Investigating`, or `Action Required`, the current investigator or a manager can schedule up to 100 interviews with an internal subject or external subject reference and an active user holding `Investigate Compliance Cases`. Scheduling requires `scheduled_at`, `purpose`, and `rationale`; optional `location` is retained. An interview may be rescheduled or terminally marked `Conducted` with `conducted_at` and `summary`, or `Cancelled` with `cancellation_reason`. Each interview retains at most 20 complete material before/after event snapshots, actor/time, rationale, version, and recursively canonical SHA-256 fingerprint. Read access follows the case workspace. These deliberate records do not prove participant identity, attendance, recording/transcription, credibility, truth, privilege, legal sufficiency, or investigative quality.

A manager can issue at most 20 legal holds per open case. `scope`, one to 50 `systems`, one to 50 `data_categories`, `preservation_start_at` no later than now, and one to 100 distinct active `custodian_ids` are required; `legal_basis_reference` is optional. The server owns reference/version, exact current case/event, issuer/custodian snapshots, time, and recursively canonical SHA-256 fingerprint. Full paginated history follows current case-workspace authorization. `GET /api/my-compliance-case-legal-holds` returns only the exact custodian's safe instruction, acknowledgement, release time, and fingerprints without case allegation or internal case/event/actor snapshots. Only that current active custodian can acknowledge once with `statement` and optional `comment`. A different current manager releases once with `summary` only after every still-active custodian acknowledges; every hold must be released before case closure. These records evidence deliberate internal instructions and acknowledgements, not source-system discovery/collection, deletion suspension, external delivery, eDiscovery execution, legal advice, legal sufficiency, or preservation effectiveness.
## Governed privacy management

When `MODULE_PRIVACY_MANAGEMENT_ENABLED=true`, authenticated privacy users use:

- `GET|POST /api/privacy-processing-activities`
- `GET|PUT /api/privacy-processing-activities/{activity}`
- `GET /api/privacy-processing-activities/{activity}/versions`
- `GET|POST /api/privacy-processing-activities/{activity}/assessments`
- `GET|POST /api/privacy-rights-requests`
- `GET /api/privacy-rights-requests/{rightsRequest}`
- `GET|POST /api/privacy-rights-requests/{rightsRequest}/events`

Lists and histories validate `page >= 1` and `per_page` from 1 through 100. Registration/revision records complete immutable processing-context versions. Assessment records bind the exact latest activity version and require separation from the owner and latest version author. Server-owned number, state evidence, version, snapshots, attribution, timestamps, and fingerprints are prohibited in caller payloads. Activation requires an approved assessment of the unchanged latest version; later material change derives `Assessment Required`.

Rights requests use a separate sensitive authorization boundary: `Manage Privacy Rights` sees and governs all records, `Handle Privacy Rights` sees and advances current assignments only, and `Read Privacy Rights` is read-only. General privacy-read permission does not expose them. The forward lifecycle is `received` → `identity_verification` → `in_progress` → `fulfilled`, with terminal `denied` or `withdrawn` alternatives. Identity review, response, denial, and unverified delivery-reference fields are required at their applicable decisions. Each event retains a complete request/assignment snapshot, actor/time, version, and fingerprint. Due state compares the operator-selected due time rather than calculating a statutory deadline.

Inputs and judgments are deliberate. These endpoints do not discover personal data, determine legal basis or entitlement, provide legal advice, manage consent, authenticate a data subject, search or alter source systems, generate/transmit responses, prove delivery, calculate regulatory deadlines, validate controls, or prove regulatory compliance.

## Governed model risk management

When `MODULE_MODEL_RISK_MANAGEMENT_ENABLED=true`, authenticated model-risk users use:

- `GET|POST /api/governed-models`
- `GET|PUT /api/governed-models/{governedModel}`
- `GET /api/governed-models/{governedModel}/versions`
- `GET|POST /api/governed-models/{governedModel}/validations`

Lists and histories validate `page >= 1` and `per_page` from 1 through 100. Registration/revision records immutable complete material versions. Independent validation binds the exact latest version and excludes its owner, developer, and latest author. Server-owned code, governance state, versions, snapshots, attribution, timestamps, and fingerprints are prohibited in caller payloads. Approved or conditionally approved validation derives production use; conditional approval requires explicit restrictions; a later material revision derives revalidation-required state.

`validation_state` is derived from the latest exact-version review and becomes `Validation Expired` after its validity date passes.

Inputs and judgments are deliberate. These endpoints do not discover or execute models, ingest telemetry, calculate performance or statistical tests, validate data or regulatory compliance, manage source code/deployment, or provide quantitative aggregate model-risk assurance.

## Governed system authorization

When `MODULE_SYSTEM_AUTHORIZATION_ENABLED=true`, authenticated authorization users use:

- `GET /api/system-authorization-packages`
- `POST /api/applications/{application}/authorization-packages`
- `GET /api/system-authorization-packages/{package}`
- `GET|POST /api/system-authorization-packages/{package}/decisions`
- `GET|POST /api/system-authorization-packages/{package}/monitoring-reviews`

Lists and histories validate `page >= 1` and `per_page` from 1 through 100. Submission requires `system_boundary`, `impact_level` (`Low`, `Moderate`, or `High`), one or more `data_classifications`, arrays of authorized `control_ids`, `risk_ids`, and `open_findings`, a `monitoring_strategy`, optional `poam_reference`, and `change_summary`. The server owns versions, application/control/risk snapshots, attribution, timestamps, and fingerprints. Only the latest unchanged package can be independently authorized. `authorized_with_conditions` requires conditions; authorization requires a future `valid_until`; only an active authorization can later be revoked.

Package, decision, and monitoring-review history is append-only and capped at 100 records per parent. Packages specify a one-to-365-day review cadence. An independent monitor records deliberate metrics/findings, outcome (`effective`, `needs_action`, or `revocation_recommended`), required actions, and summary against the complete current authorization baseline. Changed context cannot be confirmed effective; adverse outcomes derive action-required state and otherwise the server derives current/overdue state. Authorization becomes `authorization_expired` after validity passes. Inputs are deliberate: these endpoints do not discover boundaries, collect telemetry/evidence, validate controls or evidence, execute remediation, automatically authorize/revoke, or provide compliance assurance. See `docs/SYSTEM_AUTHORIZATION.md`.

## Governed ESG materiality management

When `MODULE_ESG_MANAGEMENT_ENABLED=true`, authenticated ESG users use:

- `GET|POST /api/esg-material-topics`
- `GET|PUT /api/esg-material-topics/{topic}`
- `GET /api/esg-material-topics/{topic}/versions`
- `GET|POST /api/esg-material-topics/{topic}/assessments`
- `GET|POST /api/esg-material-topics/{topic}/goals`
- `GET /api/esg-goals/{goal}`
- `GET|POST /api/esg-goals/{goal}/kpis`
- `GET /api/esg-kpis/{kpi}`
- `GET|POST /api/esg-kpis/{kpi}/observations`
- `GET|POST /api/esg-kpi-observations/{observation}/validations`
- `GET|POST /api/esg-disclosures`
- `GET /api/esg-disclosures/{disclosure}`
- `GET|POST /api/esg-disclosures/{disclosure}/decisions`

Lists and histories validate `page >= 1` and `per_page` from 1 through 100. `Manage ESG` registers topics. `Own ESG Topics` users inspect and revise their assigned topics. `Assess ESG` records decisions, and `Read ESG` inspects all retained history. The owner and latest-version author cannot assess that version.

Registration requires a pillar, owner, description, impact/risk/opportunity context, stakeholder groups, organizational boundary, review date, and change summary. Framework and source references are deliberate inputs. The server owns the code, status, version, snapshot, attribution, time, and fingerprint. Material changes append an immutable version and derive `Review Required`; only retirement may be caller-requested and it is terminal.

Assessment requires one-through-five impact and financial scores, stakeholder evidence, methodology, decision, summary, and a future review date. It binds the exact latest unchanged version and derives `Material`, `Not Material`, or `Review Required`. Version and assessment histories are independently capped at 100 records.

Goals require the latest exact independent `Material` decision and retain complete topic/assessment evidence. KPIs define fixed-decimal baseline/target, unit, direction, method, source, owner, and one-to-365-day frequency. Observations retain the exact KPI/goal context and the server derives only `Target met` or `Target not met`, next due time, and aggregate goal state. Caps are 100 goals per topic, 100 KPIs per goal, and 1,000 observations per KPI.

`Validate ESG Data` records up to 20 independent completeness/accuracy/consistency judgments against an exact observation. `Manage ESG` prepares up to 100 versions per uppercase disclosure key using 1–100 latest validated, unique, in-period observations. Each version retains its period, framework references, narrative, selected validation snapshots, preparer/time, and fingerprint. `Approve ESG Disclosures` independently approves or rejects only the latest version; the preparer and selected validators are excluded, and approval rechecks the current latest-validation boundary. Server-owned snapshots, versions, attribution, timestamps, and fingerprints are prohibited.

The REST interface maintains records; operator workspaces provide paginated read-only inspection. These endpoints do not discover topics or ESG data, collect observations automatically, calculate emissions, forecast trajectories, authenticate sources, automate validation tests, draft or submit disclosures automatically, provide external assurance, or establish reporting-framework compliance. See `docs/ESG_MANAGEMENT.md`.
