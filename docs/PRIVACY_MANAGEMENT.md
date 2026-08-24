# Privacy management

The enterprise Privacy Management foundation is enabled with `MODULE_PRIVACY_MANAGEMENT_ENABLED=true`. It provides a permission-scoped record of processing activities, independent privacy impact assessment (DPIA) evidence, and a separately permissioned sensitive privacy-rights request register.

## Authorization

- `Manage Privacy` registers and revises processing activities and may perform assessments subject to independence.
- `Own Privacy Activities` sees and revises only currently owned activities.
- `Assess Privacy` sees the register and records assessments.
- `Read Privacy` sees the complete read-only register and history.
- The service repeats authorization after locking the current activity. The owner and latest activity-version author cannot assess that version.
- `Manage Privacy Rights` records sensitive rights requests, assigns active authorized handlers, sees the full register, and may handle or reassign a request.
- `Handle Privacy Rights` sees and advances only currently assigned requests. `Read Privacy Rights` sees the complete read-only rights register. General `Read Privacy` does not grant access to data-subject request records.
- Rights-request authorization is repeated after locking the current request and before payload validation or state disclosure.

## Governed record of processing

Registration assigns a server number and starts the activity in `Draft`. Each version retains the complete material activity context: accountable owner identity, purpose, lawful basis, data-subject and personal-data categories, special-category flag, recipients, systems/vendors, processing locations, transfer state/safeguards, retention, security measures, source reference, next review date, actor/time, rationale, version, and SHA-256 fingerprint.

An activity has at most 100 immutable versions. A material change to an `Active` activity derives `Assessment Required`. `Retired` is terminal through product interfaces. Dates and categories are deliberate operator inputs rather than discovered facts.

## Privacy impact assessment

An independent assessor evaluates the exact latest retained activity version and records necessity, proportionality, risk summary, bounded mitigation statements, ordinal residual risk, decision, decision summary, next review date, actor/time, version, and SHA-256 fingerprint. At most 100 immutable assessments are retained per activity. Only an `Approved` assessment of the unchanged latest version permits activation.

REST histories are paginated at 1–100 records per page. The operator workspace exposes the same activity, version, assessment, attribution, and fingerprint evidence.

## Privacy rights requests

An authenticated authorized manager deliberately records an access, correction, deletion, portability, restriction, objection, or other request received through a stated channel. Fynix assigns a server number and retains the data-subject name, optional contact/reference, request scope, optional jurisdiction reference, received time, operator-selected due time, active authorized handler, opener, and initial event.

The forward-only lifecycle is `Received` → `Identity Verification` → `In Progress` → `Fulfilled`. A request may become terminal `Denied` after identity review or `Withdrawn` before fulfillment. Moving into progress requires an identity-verification statement; fulfillment requires a response summary and unverified delivery reference; denial requires a decision basis. Terminal requests cannot change through product interfaces.

Each event retains the complete sensitive request, assignee and opener identities, before/after status, rationale, actor/time, server version, and SHA-256 fingerprint. Scoped REST and operator history expose only assigned handlers, rights managers, or explicit rights readers. The displayed due state is `current`, `overdue`, or `complete`; it compares the deliberately entered due time and does not calculate a statutory deadline.

REST routes:

- `GET|POST /api/privacy-rights-requests`
- `GET /api/privacy-rights-requests/{rightsRequest}`
- `GET|POST /api/privacy-rights-requests/{rightsRequest}/events`

## Deliberate limits

All rights-request identity, scope, jurisdiction, status, response, decision, and delivery details are deliberate operator assertions. Fynix does not provide a public/anonymous intake portal, authenticate the data subject, discover responsive data, search or export source systems, redact documents, execute correction/deletion/restriction, transmit a response, prove delivery/receipt, determine legal entitlement, calculate regulatory deadlines, or automate fulfillment. The wider privacy foundation does not discover or classify data, scan systems, determine lawful basis, provide legal advice, manage consent/cookies, validate mitigation effectiveness, authenticate source references, or automate DPIAs. It does not claim compliance with GDPR, CCPA, or another law. Existing incident notification governance remains a separate deliberate workflow.
