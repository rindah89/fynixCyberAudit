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

Policy owners and users with `Update Policies` launch a campaign with `POST /api/policies/{policy}/acknowledgement-campaigns`. Payloads require `title`, a future `due_at`, and `audience_user_ids` containing one to 500 distinct active user IDs; `instructions` is optional. The policy must be currently effective, non-retired, and have readable embedded body content. The server allocates the campaign version and snapshots policy content, context, revision history, update time, and SHA-256 fingerprint.

Authenticated users list only their assignments through `GET /api/policy-acknowledgements/mine?page=1&per_page=50` and acknowledge their own open assignment through `POST /api/policy-acknowledgement-assignments/{assignment}/acknowledge` with `{"acknowledged": true}` plus optional `comment` and `client_reference`. The server owns the acknowledgement statement, actor, time, and snapshots. Policy owners/editors use `GET /api/policy-acknowledgement-campaigns/{campaign}/report` for paginated assignment status and `POST /api/policy-acknowledgement-campaigns/{campaign}/close` for one governed closure. List page size is capped at 100. Acknowledgements remain accepted after the due time until closure; closure prevents later submissions. This is attributable application acknowledgement, not qualified electronic signature, identity proofing, HR audience synchronization, training completion, or notification-delivery evidence.

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

Third-party risk endpoints require `Manage Third Party Risk`:

- `POST /api/vendors/{vendor}/risk-assessments`
- `POST /api/vendors/{vendor}/risks` with `risk_id`
- `POST /api/vendors/{vendor}/risk-decisions`
- `POST /api/vendors/{vendor}/risk-reviews`
- `POST /api/vendors/{vendor}/fourth-party-dependencies`

Dependency history is readable through `GET /api/vendors/{vendor}/fourth-party-dependencies` by third-party risk managers, users with `Read Vendors`, and the assigned vendor manager. Cross-vendor concentration is readable through `GET /api/third-party-risk/fourth-party-concentrations` only by third-party risk managers and users with `Read Vendors`. Both reads accept `page` and `per_page` with a maximum page size of 100.

Assessments accept 1–5 likelihood, impact, residual likelihood, and residual impact values. Categories are `cybersecurity`, `privacy`, `operational`, `financial`, `concentration`, `geographic`, `compliance`, `reputational`, and `subcontractor`. An optional `survey_id` must identify a completed `vendor_assessment` survey for the same vendor with a score and scoring timestamp. The server allocates versions, derives both scores, and snapshots the survey score; clients cannot supply derived fields.

Only existing risks classified as `third_party` may be linked. Decisions are `approved`, `conditionally_approved`, `rejected`, and `terminated`. Approval requires an assessment, at least one linked risk, and future expiration/review dates. Reviews require a current approval; outcomes are `satisfactory`, `needs_action`, and `terminate`. `evidence_attachment_ids` optionally accepts up to 20 distinct accepted data-request attachments downloadable by the reviewer. The server retains copies bounded to 10 MiB per file and 50 MiB per review and returns append-only provenance, size, disk/path, and SHA-256 snapshots under `data.evidence`; hashes prove byte identity rather than truth, sufficiency, authenticity, or review inference. Evidence references remain unverified external text. See `docs/THIRD_PARTY_RISK_MANAGEMENT.md` for workflow details and limitations.

A fourth-party declaration requires exactly one known `fourth_party_vendor_id` or external `fourth_party_name`, `status` (`active` or `exited`), `category` (`cloud_infrastructure`, `data_processing`, `technology_service`, `financial_service`, `logistics`, `professional_service`, or `other`), `criticality` (`low`, `medium`, `high`, or `critical`), `service_description`, and `rationale`. `business_service_id`, `data_access`, and `source_reference` are optional. The server normalizes identity, allocates the per-primary-vendor/dependency version, records the actor/time, and snapshots material vendor/service/dependency values. History and concentration groups default to 50 records per page. Current concentration uses only the latest version for each primary-vendor/dependency pair; an `exited` latest version removes that pair. Bands are `limited` for one non-high dependency, `moderate` for two to four primary vendors or at least one high/critical dependency, and `high` for at least five primary vendors or at least three high/critical dependencies. These are deterministic inventory roll-ups, not externally verified corporate ownership, continuous monitoring, or quantitative loss concentration.

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

Issue lifecycle endpoints use source type `risk`, `vendor`, `ai`, `resilience`, or `control_test`:

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
