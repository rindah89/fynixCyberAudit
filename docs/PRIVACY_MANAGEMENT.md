# Privacy management

The enterprise Privacy Management foundation is enabled with `MODULE_PRIVACY_MANAGEMENT_ENABLED=true`. It provides a permission-scoped record of processing activities and independent privacy impact assessment (DPIA) evidence.

## Authorization

- `Manage Privacy` registers and revises processing activities and may perform assessments subject to independence.
- `Own Privacy Activities` sees and revises only currently owned activities.
- `Assess Privacy` sees the register and records assessments.
- `Read Privacy` sees the complete read-only register and history.
- The service repeats authorization after locking the current activity. The owner and latest activity-version author cannot assess that version.

## Governed record of processing

Registration assigns a server number and starts the activity in `Draft`. Each version retains the complete material activity context: accountable owner identity, purpose, lawful basis, data-subject and personal-data categories, special-category flag, recipients, systems/vendors, processing locations, transfer state/safeguards, retention, security measures, source reference, next review date, actor/time, rationale, version, and SHA-256 fingerprint.

An activity has at most 100 immutable versions. A material change to an `Active` activity derives `Assessment Required`. `Retired` is terminal through product interfaces. Dates and categories are deliberate operator inputs rather than discovered facts.

## Privacy impact assessment

An independent assessor evaluates the exact latest retained activity version and records necessity, proportionality, risk summary, bounded mitigation statements, ordinal residual risk, decision, decision summary, next review date, actor/time, version, and SHA-256 fingerprint. At most 100 immutable assessments are retained per activity. Only an `Approved` assessment of the unchanged latest version permits activation.

REST histories are paginated at 1–100 records per page. The operator workspace exposes the same activity, version, assessment, attribution, and fingerprint evidence.

## Deliberate limits

This foundation does not discover or classify data, scan systems, determine lawful basis, provide legal advice, manage consent/cookies, fulfill data-subject requests, calculate regulatory deadlines, manage breach notification, validate mitigation effectiveness, authenticate source references, or automate DPIAs. It does not claim compliance with GDPR, CCPA, or another law. Existing incident notification governance remains a separate deliberate workflow.
