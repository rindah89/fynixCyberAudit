# AI governance foundation

Fynix provides a governed inventory and approval workflow for AI systems and their use cases. The foundation is deliberately narrower than an end-to-end AI governance platform.

## Operator workflow

Users with `Manage AI Governance` can create and edit AI system inventory records from **AI Governance → AI Systems**. Each record identifies its owner, provider and model, deployment type, lifecycle, criticality, intended and prohibited uses, human oversight, data categories, and next review date. Accountable owners may view their assigned systems but cannot change governance records.

The REST and operator workflows register use cases and owners, record versioned risk assessments, map existing governed controls and risks, record a decision, and then record monitoring reviews. Assessments, decisions, monitoring reviews, and linked governed evidence are append-only through product interfaces. A new assessment version or current system, use-case, or mapped control/risk values that differ from the approval snapshot require reapproval. Mutable inventory records do not provide permanent change history: reverting them to the approved snapshot restores the prior derived approval state.

Approval requires the current assessment plus at least one mapped control and governed risk. The server serializes approval and monitoring snapshots with changes to the use case, system, and mapped control/risk graph, then snapshots the assessed version and residual score, mapped identities, system and use-case inputs, and a governance fingerprint. Approved use cases receive a monitoring due date. A `needs_action` or `suspended` monitoring result opens an AI governance issue.

Each monitoring review may select one to 20 accepted audit-evidence files that the reviewer is authorized to access. While enforcing 10 MiB per-file and 50 MiB per-review bounds, Fynix retains the selected bytes and snapshots attachment/response/request/audit identities, accepted state, metadata, actual size, disk/path, SHA-256, reviewer, and time. Downloads reauthorize both the AI-system workspace and exact linked attachment. An external evidence reference remains optional, operator-supplied, unverified text.

## Derived status

The workspace derives missing assessment, control mapping, risk mapping, approval, reapproval, expired approval, monitoring due, action-required, suspended, and governed states. Scores use the existing 1–5 likelihood and impact scales; inherent and residual scores are server-derived products.

## Limitations

Evidence selection and monitoring values remain deliberate operator/integration inputs. A content hash proves retained-byte identity, not truth, sufficiency, authenticity, or that the reported performance metrics were derived from the file. This foundation does not discover models, classify regulations, automatically collect monitoring evidence, ingest fairness or model-performance telemetry, generate model cards, connect to external AI registries, enforce runtime policy, or automate remediation. Monitoring issues use the separately documented governed remediation and independent-closure workflow, whose closure requires separate accepted audit attachments with access, presence, size, and SHA-256 checks. It is not a claim of full AI governance or ServiceNow IRM parity.

See the AI governance section of `docs/API_DOCUMENTATION.md` for routes and payload rules.
