# AI governance foundation

Fynix provides a governed inventory and approval workflow for AI systems and their use cases. The foundation is deliberately narrower than an end-to-end AI governance platform.

## Operator workflow

Users with `Manage AI Governance` can create and edit AI system inventory records from **AI Governance → AI Systems**. Each record identifies its owner, provider and model, deployment type, lifecycle, criticality, intended and prohibited uses, human oversight, data categories, and next review date. Accountable owners may view their assigned systems but cannot change governance records.

The REST workflow registers use cases and owners, records versioned risk assessments, maps existing governed controls and risks, records a decision, and then records monitoring reviews. Assessments, decisions, and monitoring reviews are append-only through product interfaces. A new assessment version or current system, use-case, or mapped control/risk values that differ from the approval snapshot require reapproval. Mutable inventory records do not provide permanent change history: reverting them to the approved snapshot restores the prior derived approval state.

Approval requires the current assessment plus at least one mapped control and governed risk. The server snapshots the assessed version and residual score, mapped identities, system and use-case inputs, and a governance fingerprint. Approved use cases receive a monitoring due date. A `needs_action` or `suspended` monitoring result opens an AI governance issue; the external evidence reference is operator-supplied, unverified text.

## Derived status

The workspace derives missing assessment, control mapping, risk mapping, approval, reapproval, expired approval, monitoring due, action-required, suspended, and governed states. Scores use the existing 1–5 likelihood and impact scales; inherent and residual scores are server-derived products.

## Limitations

This foundation does not discover models, classify regulations, verify evidence, ingest fairness or model-performance telemetry, generate model cards, connect to external AI registries, enforce runtime policy, close issues, or automate remediation. It is not a claim of full AI governance or ServiceNow IRM parity.

See the AI governance section of `docs/API_DOCUMENTATION.md` for routes and payload rules.
