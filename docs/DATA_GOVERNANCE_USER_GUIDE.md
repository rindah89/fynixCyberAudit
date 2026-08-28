# Suite data governance user guide

CyberAudit is the central oversight application for Fynix data governance. Source applications remain responsible for their own records and business actions.

## Reading the oversight page

For each application, review:

- **Binding** — whether the application has a dedicated enabled signing identity.
- **Freshness** — whether its latest twelve-control statement is current.
- **Effective controls** — controls supported by the submitted status and central validation.
- **Open and waived exceptions** — risks requiring remediation, evidence, or time-bound acceptance.
- **Operability** — overdue privacy requests, active legal holds, pending processor reviews, and disposition receipts.

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

Processor entries begin as **pending review**. Review purpose, data categories, countries, transfer mechanism, agreement owner, and review date before approval. One entry does not prove the register is complete.

Recovery evidence must represent a successful completed restore drill, include a controlled reference, and fall within the required evidence window. Future-dated or self-attested evidence must not promote a control to effective.

## Exceptions and waivers

Assign every exception an owner, due date, and resolution plan. A waiver must be authorized, documented, and time-bound. CyberAudit reopens expired waivers when the source continues to report the gap. Never change a status merely to make readiness pass.
