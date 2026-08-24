# Third-party risk management foundation

Fynix provides a governed third-party risk workflow on top of its existing vendor inventory, surveys, documents, portal, scoring, and exports. The foundation is narrower than continuous third-party monitoring or a complete supplier lifecycle platform.

## Operator workflow

Users with `Manage Third Party Risk` can record a versioned vendor risk assessment through REST. A vendor relationship manager can discover and inspect only their assigned record in the read-only **Risk Management → Third-Party Risk** workspace but cannot create governance records unless separately authorized. That dedicated workspace shows assessment history, linked risks, decision history, and review history without exposing unrelated vendor administration.

An assessment uses the standard 1–5 likelihood and impact scales. The server derives inherent and residual scores, allocates the next version, and may snapshot a completed, scored `vendor_assessment` survey belonging to the same vendor. The survey remains the governed due-diligence source; its score and scoring timestamp are required but are not recalculated by the assessment workflow.

Before a decision, the operator links at least one existing risk classified as `third_party`. Approved or conditionally approved decisions snapshot the assessment version, residual score, linked risk identities, and governance fingerprint. A new assessment or current vendor/risk values that differ from that snapshot require a new decision. Mutable vendor records do not provide permanent field-level change history; reverting to the approved snapshot restores the prior derived state.

Periodic reviews are attributable to both the reviewer and the approved decision baseline. An authorized reviewer may deliberately bind one to 20 accepted audit-evidence attachments they can access. Fynix retains bounded copies and snapshots the attachment/response/request/audit identities, accepted state, metadata, actual size, disk/path, SHA-256, reviewer, and time. Downloads reauthorize both the vendor workspace and exact linked attachment; unauthorized viewers receive no evidence count, action, metadata, or content. `needs_action` and `terminate` outcomes open persistent third-party risk issues. Later satisfactory reviews do not hide open issues. Assessments, decisions, reviews, and linked evidence are append-only through product interfaces. Evidence references remain operator-supplied, unverified external text.

Managers can also record immutable, versioned fourth-party dependency declarations for a primary vendor. A declaration identifies either another vendor record or a normalized external organization name, affected business service, service category and description, criticality, data-access indicator, lifecycle state, rationale, and optional source reference. The server snapshots the primary vendor, fourth party, affected service, and material dependency values. Recording another version with `exited` state removes that vendor/dependency pair from current concentration without rewriting history.

Current concentration is derived from the latest declaration for each primary-vendor/dependency pair. `limited`, `moderate`, and `high` bands use the number of distinct primary vendors plus the number of high/critical dependencies. Assigned vendor managers can inspect only their vendor's complete history and see an aggregate count/band without other vendor identities. Users with `Manage Third Party Risk` or `Read Vendors` can inspect cross-vendor concentration, including the contributing primary vendors. The operator relation history is paginated and exportable to authorized readers.

## Derived status

The vendor workspace derives assessment-required, risk-link-required, decision-required, reapproval-required, approved, conditionally-approved, rejected, terminated, approval-expired, review-overdue, action-required, and termination-required states.

## Limitations

Evidence selection, dependency declarations, and review conclusions remain deliberate operator/integration inputs. A SHA-256 hash proves retained-byte identity, not truth, sufficiency, authenticity, or that the review conclusion was derived from the file. Fourth-party identity and ownership are not externally verified. This foundation does not provide supplier discovery, sanctions or financial feeds, attack-surface telemetry, ownership intelligence, regulatory content, contract lifecycle management, assessment-evidence ingestion, automatic evidence collection, automatic reassessment, or automated remediation. Review issues use the separately documented governed remediation and independent-closure workflow with separate content-hashed accepted audit evidence. Vendor surveys and documents remain separately governed workflows. This is not a claim of complete ServiceNow third-party risk management parity.

See the third-party risk section of `docs/API_DOCUMENTATION.md` for routes and payload constraints.
