# Suite data governance user guide

CyberAudit is the central oversight application for Fynix data governance. Source applications remain responsible for their own records and business actions.

## Reading the oversight page

For each application, review:

- **Binding** — whether the application has a dedicated enabled signing identity.
- **Freshness** — whether its latest twelve-control statement is current.
- **Effective controls** — controls supported by the submitted status and central validation.
- **Open and waived exceptions** — risks requiring remediation, evidence, or time-bound acceptance.
- **Operability** — overdue privacy requests, active legal holds, pending processor reviews, and disposition receipts.
- **Evidence review** — pending privacy, disposition, processor, and recovery evidence requiring a reviewer other than the submitting application.

`Partially effective`, `ineffective`, `unknown`, stale, or missing means attention is required. A `recorded` receipt only proves CyberAudit accepted the signed submission. It does not prove that deletion, privacy fulfillment, recovery, or vendor review occurred.

## Privacy requests

1. Verify the requester's identity in the source application.
2. Open the request with an opaque subject reference, right, lawful basis, and request date.
3. Complete discovery and fulfillment in every owning application.
4. Close the request only with controlled evidence. CyberAudit calculates a 30-day due date and reports overdue open requests.

Do not enter names, email addresses, tokens, query strings, signed URLs, or raw document paths. Accepted references are opaque identifiers and controlled `urn:fynix:` or `evidence://` evidence references.

## Retention, holds, and disposition

Define a record class, retention period, and action. CyberAudit derives eligibility from the source record date plus the retention period. An active legal hold blocks a disposition receipt. Release a hold only with the required legal authority.

A disposition receipt is evidence metadata; the source application must actually delete, anonymise, or archive the record. Keep the control partial if source execution is not independently verified.

## Processors and recovery

CyberAudit reconciles the complete processor and international-transfer inventory from `SUITE_GOVERNANCE_PROCESSOR_INVENTORY_JSON` every day. The JSON must contain a non-empty list for every required application. Each item supplies `name`, `purpose`, `data_categories`, `processing_countries`, `transfer_mechanism`, `agreement_owner`, `agreement_evidence_ref`, `agreement_evidence_sha256`, and `review_due_at`. A missing application or duplicate processor name fails the reconciliation.

Processor entries begin as **pending review**. Review purpose, data categories, countries, transfer mechanism, agreement owner, evidence digest, and review date before approval. Unchanged daily reconciliations preserve approval. Any material change returns the entry to pending review; removal marks it inactive. After all active entries are approved, an independent reviewer certifies the exact register. A changed inventory invalidates the prior certification automatically.

Operators can run `php artisan fynix:reconcile-processor-inventory` after an authorized inventory change. The scheduler runs reconciliation at 02:30 and publishes the governance statement at 02:45. A failed reconciliation must be investigated; do not publish or certify a hand-edited partial register.

CyberAudit records every reconciliation outcome. DG-11 can remain effective only when the latest run succeeded within the evidence freshness window. A failed or stale run invalidates every prior register certification until a complete reconciliation succeeds and any changed entries are reviewed again.

The oversight and readiness responses expose `processor_inventory_reconciliation`. `missing_failed_or_stale` always makes readiness `attention_required`, even if older processor approvals and statements are still present.

Recovery evidence must represent a successful completed restore drill, include a controlled reference, and fall within the required evidence window. Future-dated or self-attested evidence must not promote a control to effective.

Reviewers compare the stored SHA-256 value to the controlled artifact before approving. Rejection records a reason and leaves or opens an exception. Application operators cannot approve their own processor register, privacy completion, disposition receipt, or restore drill.

Approval is bound to a canonical snapshot of the submitted control record and its review evidence. Changing an evidence digest, reference, processor purpose, processing country, transfer mechanism, agreement, or review date after approval invalidates that approval. Setting an `approved` status without its matching independent review record never satisfies a control.

Each application's operability report includes `invalid_or_tampered_reviews`. A non-zero value always fails readiness and requires a new independent review after the underlying record is corrected; changing the status alone does not clear it.

## Exceptions and waivers

Assign every exception an owner, due date, and resolution plan. A waiver must be authorized, documented, and time-bound. CyberAudit reopens expired waivers when the source continues to report the gap. Never change a status merely to make readiness pass.
