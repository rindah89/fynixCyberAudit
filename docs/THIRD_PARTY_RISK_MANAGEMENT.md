# Third-party risk management foundation

Fynix provides a governed third-party risk workflow on top of its existing vendor inventory, surveys, documents, portal, scoring, and exports. The foundation is narrower than continuous third-party monitoring or a complete supplier lifecycle platform.

## Operator workflow

Users with `Manage Third Party Risk` can record a versioned vendor risk assessment through REST. A vendor relationship manager can discover and inspect only their assigned record in the read-only **Risk Management → Third-Party Risk** workspace but cannot create governance records unless separately authorized. That dedicated workspace shows assessment history, linked risks, decision history, and review history without exposing unrelated vendor administration.

An assessment uses the standard 1–5 likelihood and impact scales. The server derives inherent and residual scores, allocates the next version, and may snapshot a completed, scored `vendor_assessment` survey belonging to the same vendor. The survey remains the governed due-diligence source; its score and scoring timestamp are required but are not recalculated by the assessment workflow.

Before a decision, the operator links at least one existing risk classified as `third_party`. Approved or conditionally approved decisions snapshot the assessment version, residual score, linked risk identities, and governance fingerprint. A new assessment or current vendor/risk values that differ from that snapshot require a new decision. Mutable vendor records do not provide permanent field-level change history; reverting to the approved snapshot restores the prior derived state.

Periodic reviews are attributable to both the reviewer and the approved decision baseline. `needs_action` and `terminate` outcomes open persistent third-party risk issues. Later satisfactory reviews do not hide open issues. Assessments, decisions, and reviews are append-only through product interfaces. Evidence references are operator-supplied, unverified external text.

## Derived status

The vendor workspace derives assessment-required, risk-link-required, decision-required, reapproval-required, approved, conditionally-approved, rejected, terminated, approval-expired, review-overdue, action-required, and termination-required states.

## Limitations

This foundation does not provide supplier discovery, sanctions or financial feeds, attack-surface telemetry, fourth-party mapping, regulatory content, contract lifecycle management, verified evidence ingestion, automatic reassessment, issue closure, or automated remediation. Vendor surveys and documents remain separately governed workflows. This is not a claim of complete ServiceNow third-party risk management parity.

See the third-party risk section of `docs/API_DOCUMENTATION.md` for routes and payload constraints.
