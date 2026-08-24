# Policy compliance workflow

Fynix Cyber Audit tracks policy compliance as attributable attestations against discrete policy obligations and provides a deliberately maintained regulatory source and requirement inventory. It does not ingest regulatory feeds or provide legal advice; control testing is documented separately in `docs/CONTINUOUS_CONTROL_TESTING.md`.

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
