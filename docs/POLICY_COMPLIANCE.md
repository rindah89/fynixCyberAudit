# Policy compliance workflow

Fynix Cyber Audit tracks policy compliance as attributable attestations against discrete policy obligations and provides a deliberately maintained regulatory source and requirement inventory. It does not ingest regulatory feeds or provide legal advice; control testing is documented separately in `docs/CONTINUOUS_CONTROL_TESTING.md`.

## Governed policy revisions and approval

A policy owner or user with `Update Policies` submits the current policy content and governed risk, control, and implementation mappings for review. The server allocates the next version, applies the proposed effective date to the immutable snapshot, and records the change summary, submitter, submission time, and SHA-256 fingerprint. Only one revision may be pending for a policy.

A different user with `Update Policies` approves or rejects the pending latest revision. Review re-locks the policy and mapped records and rejects a revision if current material content or mappings differ from the submitted snapshot. The decision retains the exact revision snapshot, review summary, reviewer/time, and a separate fingerprint. Approval applies the proposed effective date; rejection retains evidence and allows a corrected later version. Revision and review evidence cannot be edited or deleted through product interfaces.

Authorized users inspect complete paginated history under **Policies → Governed revision history** or through REST; `Read Policies` users may export the already scoped relation. Derived state is `unpublished`, `pending_review`, `approved_scheduled`, `current`, or `revision_required`. Drift is a current-state comparison: if mutable values are changed and later restored exactly to the approved snapshot, the approved revision becomes current again. Legacy policy edits and the editable `revision_history` field remain outside this governed lifecycle and do not become approved evidence retroactively.

The workflow records deliberate policy content and human approval. It does not authenticate uploaded documents, provide legal review, prove reviewer competence or organizational independence beyond user separation, deliver qualified electronic signatures, distribute policy changes, infer compliance, or automatically generate or approve revisions.

## Governed policy exceptions

A policy owner or authenticated user with policy read access requests a time-bounded exception with description, business justification, risk assessment, compensating controls, effective/expiration dates, and a monitoring frequency from one to 365 days. The server owns pending status, requester/date/time, current policy and approved-revision context snapshot, and SHA-256 fingerprint. Request content is immutable through product interfaces.

A different user with `Update Policies` approves or denies a pending request. Approval is rejected if the policy or approved-revision context changed after submission; an independent denial terminalizes stale evidence so a replacement can be submitted. An approved exception may later receive one attributable revocation decision. Every decision is versioned and retains the complete pre-decision exception snapshot, summary, actor/time, and fingerprint. Only approved exceptions inside their effective window qualify an attestation; revocation immediately removes eligibility.

Approval schedules the first review at the requested frequency, capped by the expiration date. A different `Update Policies` user from both the requester and latest decision maker records an append-only monitoring review with `effective`, `needs_action`, or `revoke_recommended`, a summary, a deliberate control-effectiveness statement, and an optional operator-supplied evidence reference. The server snapshots the approved exception, latest decision, approved policy context, current policy context, actor/time, next review date, and SHA-256 fingerprint. A changed policy context cannot be confirmed effective. `needs_action` and `revoke_recommended` derive persistent `action_required` state until a later current-context effective review or revocation; due and overdue state are derived from the server-owned next-review date.

Authorized users inspect complete paginated request, decision, and monitoring history under **Policies → Policy exception history** or through REST, with private relation-scoped export. Existing exception rows without a governance fingerprint are visibly labeled `Legacy` and cannot enter the governed decision or monitoring service. Previously approved, in-window legacy rows remain eligible for existing attestations for backward compatibility, but are not governed request/decision evidence. The product records deliberate requests, decisions, and monitoring judgments. It does not prove legal approval, quantify risk, verify the evidence reference, validate compensating-control effectiveness, provide electronic signatures, make automatic expiry decisions, collect evidence automatically, or execute remediation.

## Regulatory change inventory

Policy editors register regulatory sources through REST with authority, jurisdiction, reference URL, accountable owner, and lifecycle status. Editors and assigned source or requirement owners publish append-only requirement versions through REST. The server allocates version numbers, snapshots the source and selected active policy/control records, and fingerprints the complete version content. The first version is a new requirement; later versions explicitly identify an amendment, guidance update, or repeal.

Authorized users inspect **Compliance → Regulatory Requirements**. The workspace exposes the current derived state and complete paginated immutable version and change-assessment history. Each assessment records applicability, impact, summary, rationale, actor/time, optional action owner/due date, and complete requirement/source/policy/control snapshots. High or critical applicable changes and items under review require an accountable action and due date. Current versions without an assessment show `assessment_required`; due dates derive `review_overdue` or `action_overdue`.

REST integrations maintain sources, requirements, versions, and assessments through the routes documented in `docs/API_DOCUMENTATION.md`; the operator workspace is inspection-only. Readers with `Read Policies` see all records; assigned source and requirement owners see their own scope. The assessment export is available from the already scoped requirement history and includes deterministic snapshot JSON and the version fingerprint.

All regulatory content, mappings, applicability, impact, summaries, and actions are deliberate human inputs. Fynix does not discover regulatory changes, authenticate source content, interpret law, infer applicability or mappings, synchronize external feeds, validate legal sufficiency, or automatically remediate changes.

## Operator workflow

1. Open **Compliance → Policy Obligations**.
2. Create an obligation with a policy, accountable owner, unique code, cadence, and next due date. A related control is optional.
3. The accountable owner, the policy owner, or an operator with `Update Policies` submits an attestation.
4. Record an outcome, a required statement, and optionally an external evidence reference, policy exception, and one to 20 accepted audit-evidence attachments you are authorized to access. Fynix retains bounded copies and snapshots provenance, metadata, actual size, disk/path, SHA-256, actor, and time.
5. Review the read-only attestation history on the obligation. Governed evidence count, action, metadata, and downloads appear only when the viewer also passes the exact attachment ACL. A correction is a new attestation; existing attestations and linked evidence cannot be changed through product interfaces.

An evidence reference is operator-supplied text for locating a record in a data request, audit item, governed repository, or other evidence system. Fynix does not verify that the referenced record exists or grant access to it. It is not a file upload and does not bypass the referenced system's access rules.

Governed attachment selection is separate from that text reference. A retained-copy SHA-256 value proves byte identity, not truth, sufficiency, authenticity, or that the attestation outcome was derived from the file.

## Policy acknowledgement campaigns

A policy owner or user with `Update Policies` can launch a campaign from a currently effective, non-retired policy that has readable embedded body content. Launch requires a title, future due time, and one to 500 distinct active users; instructions are optional. The server allocates a per-policy campaign version and snapshots the policy identity, content, document reference, scope, ownership, dates, revision history, update time, and SHA-256 fingerprint. Later policy edits do not rewrite the assigned version.

Assigned employees use **Compliance → My Policy Acknowledgements** to inspect only their assignments and the exact policy snapshot. Acknowledgement requires an explicit accepted confirmation; the server supplies the standard statement and records the assigned user, optional comment/client reference, campaign and policy snapshots, fingerprint, and timestamp. Another user cannot acknowledge the assignment, and an acknowledged assignment cannot be submitted again. A policy editor or owner uses **Compliance → Policy Campaigns** for complete paginated audience reporting and scoped export.

Pending assignments become `overdue` after the campaign due time. A campaign is `complete` when every assignment is acknowledged, `overdue` when its due time passes with pending assignments, and `closed` after an authorized owner/editor deliberately closes it. Closure preserves unacknowledged assignments as `closed_unacknowledged` and prevents later acknowledgement. Campaign definitions, assignments, and acknowledgement evidence are immutable through product interfaces except for the campaign's one governed closure.

## Compliance status

| Status | Meaning |
|---|---|
| `due` | Active obligation has not been attested and its due date has not passed. |
| `compliant` | Latest attestation is compliant and the next due date has not passed. |
| `non_compliant` | Latest attestation records a gap and the next due date has not passed. |
| `not_applicable` | Latest attestation records that the obligation does not apply. |
| `overdue` | The active obligation's next due date has passed, regardless of its previous outcome. |
| `inactive` | The obligation is not currently tracked. |

Recurring attestations calculate their next due date from the attestation time: monthly, quarterly, semi-annual, or annual. A one-time attestation clears the next due date.

## Authorization and integrity

- Policy editors create and maintain obligations.
- Assigned obligation owners and policy owners can discover their obligations without broad policy-list access; their workspace query remains owner-scoped.
- The obligation owner, policy owner, or an authorized policy editor may attest. Every attestation records the actual submitting actor.
- Unrelated users cannot attest.
- A linked policy exception must belong to the same policy and be approved and currently in effect.
- Product interfaces do not update or delete attestations; subsequent submissions append history. Database administrators remain outside this application-level guarantee.
- Inactive obligations cannot be attested.
- Obligations soft-delete. Attested obligations and referenced exceptions cannot be physically deleted while their attestation history exists.
- Each attestation records the user and timestamp.
- Campaign launch and closure reauthorize against the current policy owner/editor boundary under database locks. Acknowledgement reauthorizes against the locked current assignment and campaign.
- Soft-deleted policies and deactivated users remain attributable through retained snapshots and historical relationships; they are not eligible for new campaign audience selection.

## Integration interface

Submit, inspect, and review governed policy revisions:

- `POST /api/policies/{policy}/revisions`
- `GET /api/policies/{policy}/revisions?page=1&per_page=50`
- `POST /api/policy-revisions/{revision}/review`

Submit, inspect, decide, and revoke governed policy exceptions:

- `POST /api/policies/{policy}/exception-requests`
- `GET /api/policies/{policy}/exception-requests?page=1&per_page=50`
- `POST /api/policy-exceptions/{exception}/decisions`
- `POST /api/policy-exceptions/{exception}/monitoring-reviews`
- `GET /api/policy-exceptions/{exception}/monitoring-reviews?page=1&per_page=50`

Create an obligation:

`POST /api/policies/{policy}/obligations`

Submit an attestation:

`POST /api/policy-obligations/{obligation}/attest`

Policy detail responses include obligations, owners, related controls, current derived status, and latest attestations.

Launch and operate acknowledgement campaigns:

- `POST /api/policies/{policy}/acknowledgement-campaigns`
- `GET /api/policy-acknowledgements/mine`
- `POST /api/policy-acknowledgement-assignments/{assignment}/acknowledge`
- `GET /api/policy-acknowledgement-campaigns/{campaign}/report`
- `POST /api/policy-acknowledgement-campaigns/{campaign}/close`

## Explicit limitations

Audience selection and policy content remain deliberate operator inputs. This workflow does not synchronize HR groups, automatically assign audiences, send or prove notification delivery, require quizzes/training, provide electronic-signature identity assurance, automatically collect evidence, validate authenticity/sufficiency, or infer compliance from policy content. Regulatory inventory is deliberate and does not provide feeds or automatic policy-to-law detection. Control-test results must not be inferred from policy attestations or acknowledgements; they are recorded through the separate control-testing workflow.
