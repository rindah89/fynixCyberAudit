# Suite data governance user guide

CyberAudit is the central oversight application for Fynix data governance. Source applications remain responsible for their own records and business actions.

## Reading the oversight page

For each application, review:

- **Binding** — whether the application has a dedicated enabled signing identity.
- **Freshness** — whether its latest twelve-control statement is current.
- **Effective controls** — controls supported by the submitted status and central validation.
- **Open and waived exceptions** — risks requiring remediation, evidence, or time-bound acceptance.
- **Operational oversight** — overdue privacy requests, active legal holds, disposition receipts, every pending evidence-review queue, invalidated reviews, restore-evidence currency, and processor-register certification.
- **Evidence review** — pending privacy, disposition, processor, and recovery evidence requiring a reviewer other than the submitting application.

`Partially effective`, `ineffective`, `unknown`, stale, or missing means attention is required. A `recorded` receipt only proves CyberAudit accepted the signed submission. It does not prove that deletion, privacy fulfillment, recovery, or vendor review occurred.

No required Fynix application has a blanket privacy-rights exemption. A `not_applicable` result is rejected unless CyberAudit has an explicit, reviewed source-and-control applicability decision; the default registry contains no such exceptions.

The same operational values are included in the authenticated `/api/governance/oversight` response and the scheduled governance monitor fingerprint. A new overdue request, pending review, invalid review, stale restore drill, or uncertified processor register therefore changes the monitor result even when the application's daily statement remains current.

## Privacy requests

1. Verify the requester's identity in the source application.
2. Open the request with an opaque subject reference, right, lawful basis, and request date.
3. Complete discovery and fulfillment in every owning application.
4. Close the request only with controlled evidence. CyberAudit calculates a 30-day due date and reports overdue open requests.

Finance and PPM send a signed intake event when a source request is accepted. CyberAudit correlates later completion evidence to that same source request, so the deadline, status, and review history remain one case. A repeated intake or completion event is idempotent; it must not create a second case. If an expected intake event is absent, treat the source integration as unhealthy rather than opening a replacement case by hand.

For PPM access requests, completion evidence identifies a digest-addressed export retained in PPM's governed WORM store. Reviewers must compare the submitted SHA-256 with the artifact returned by PPM before approving completion; CyberAudit does not store the exported personal data.

Do not enter names, email addresses, tokens, query strings, signed URLs, or raw document paths. Accepted references are opaque identifiers and controlled `urn:fynix:` or `evidence://` evidence references.

CyberAudit users request their own access export with `POST /api/governance/privacy/access-export` and a controlled `identity_verification_ref`. The export includes account attributes and an explicit fail-closed manifest of user-linked audit, risk, policy, survey, import/export, governance-review, evidence-authorization, support-change, and session metadata. It excludes passwords, tokens, uploaded file contents, and evidence payloads.

Authenticated users exercise correction, restriction, objection, or deletion with `POST /api/governance/privacy/rights`. Every request requires a controlled `identity_verification_ref`; correction also requires the corrected `name`. Correction is deliberately limited to the account display name because changing a login address requires a separate ownership-verification flow.

Restriction and objection immediately stop the account from using authenticated CyberAudit processing routes. Privacy endpoints remain available so the user can retrieve an access export or submit another rights request. If access should later resume, an authorized privacy operator must review the request and remove the state through the controlled administrative process; do not edit the database directly.

Deletion performs lawful anonymization rather than removing integrity-protected audit history. It revokes API tokens, removes role and direct-permission assignments, replaces account identifiers and credentials, and soft-deletes the account. Existing audit records retain only the opaque internal user identifier needed for evidentiary integrity. Super Admin and break-glass accounts cannot self-anonymize until their emergency responsibilities are formally reassigned.

Each completed action creates a closed CyberAudit privacy request with digest-bound evidence in **pending review**. A reviewer other than the requester must verify the identity reference and resulting source state before approval. The API response and evidence trail never repeat the user's prior name, email address, token, or other erased value.

## Retention, holds, and disposition

Define a record class, retention period, and action. CyberAudit derives eligibility from the source record date plus the retention period. An active legal hold blocks a disposition receipt. Release a hold only with the required legal authority.

A disposition receipt is evidence metadata; the source application must actually delete, anonymise, or archive the record. Keep the control partial if source execution is not independently verified.

## Processors and recovery

CyberAudit reconciles the complete processor and international-transfer inventory from `SUITE_GOVERNANCE_PROCESSOR_INVENTORY_JSON` every day. The JSON must contain a non-empty list for every required application. Each item supplies `name`, `purpose`, `data_categories`, `processing_countries`, `transfer_mechanism`, `agreement_owner`, `agreement_evidence_ref`, `agreement_evidence_sha256`, and `review_due_at`. A missing application or duplicate processor name fails the reconciliation.

Processor entries begin as **pending review**. Review purpose, data categories, countries, transfer mechanism, agreement owner, evidence digest, and review date before approval. Unchanged daily reconciliations preserve approval. Any material change returns the entry to pending review; removal marks it inactive. After all active entries are approved, an independent reviewer certifies the exact register. A changed inventory invalidates the prior certification automatically.

Source applications may honestly report DG-11 as partially effective while awaiting central review. Once the latest complete inventory reconciliation is current and the exact register has an unexpired independent certification, CyberAudit promotes that result to effective and records `central_evidence_verified`. It never requires the source application to self-certify its vendor inventory.

Operators can run `php artisan fynix:reconcile-processor-inventory` after an authorized inventory change. The scheduler runs reconciliation at 02:30 and publishes the governance statement at 02:45. A failed reconciliation must be investigated; do not publish or certify a hand-edited partial register.

CyberAudit records every reconciliation outcome. DG-11 can remain effective only when the latest run succeeded within the evidence freshness window. A failed or stale run invalidates every prior register certification until a complete reconciliation succeeds and any changed entries are reviewed again.

The oversight and readiness responses expose `processor_inventory_reconciliation`. `missing_failed_or_stale` always makes readiness `attention_required`, even if older processor approvals and statements are still present.

Recovery evidence must represent a successful completed restore drill, include a controlled reference, and fall within the required evidence window. Future-dated or self-attested evidence must not promote a control to effective.

For CyberAudit itself, run `deploy/rehearse-restore.sh` against a checksum-verified backup. The drill restores into a disposable database, verifies the restored schema and application storage through suite preflight, writes a bounded JSON report, and queues its SHA-256 for review. Keep the report in the restricted governance evidence directory; never approve a backup-only receipt.

Reviewers compare the stored SHA-256 value to the controlled artifact before approving. Rejection records a reason and leaves or opens an exception. Application operators cannot approve their own processor register, privacy completion, disposition receipt, or restore drill.

Approval is bound to a canonical snapshot of the submitted control record and its review evidence. Changing an evidence digest, reference, processor purpose, processing country, transfer mechanism, agreement, or review date after approval invalidates that approval. Setting an `approved` status without its matching independent review record never satisfies a control.

Each application's operability report includes `invalid_or_tampered_reviews`. A non-zero value always fails readiness and requires a new independent review after the underlying record is corrected; changing the status alone does not clear it.

## Exceptions and waivers

Assign every exception an owner, due date, and resolution plan. A waiver must be authorized, documented, and time-bound. CyberAudit reopens expired waivers when the source continues to report the gap. Never change a status merely to make readiness pass.
