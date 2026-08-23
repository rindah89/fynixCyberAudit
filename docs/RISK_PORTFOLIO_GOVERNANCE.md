# Risk portfolio governance foundation

Fynix provides a shared governance workflow for risks classified as `enterprise`, `operational`, or `technology`. It extends the existing risk register, scoring, asset, implementation, policy, mitigation, REST, MCP, and export capabilities without claiming complete ServiceNow portfolio parity.

## Domain-aware profile

Users with `Manage Risk Portfolio` create or update a governance profile with an accountable owner, 1–25 appetite threshold, review frequency, next review date, and context notes. Each domain adds a required context:

- Enterprise risks identify the strategic objective exposed by the risk.
- Operational risks map an active business service.
- Technology risks map at least one active asset and at least one fully implemented implementation linked to an applicable control.

Accountable owners can discover and inspect only their assigned records in the read-only **Risk Management → Risk Portfolio** workspace. They cannot create profiles or reviews without the management permission.

## Attributable review

A manager records an `accepted`, `mitigate`, `transfer`, or `avoid` decision. The server snapshots the domain, inherent and residual scores, appetite threshold, asset and implementation identities and material values, operational service context, the governance profile, and a fingerprint of the material risk record. A risk above appetite cannot be accepted. Treatment decisions open a persistent governance issue.

Reviews are append-only through product interfaces and attributable to the reviewer. Current material risk, domain-context, mapping, or profile values that differ from the latest snapshot require another review. Mutable source records do not provide permanent field-level change history: reverting them to the reviewed snapshot restores the prior derived state. Evidence references are operator-supplied, unverified external text.

Once a portfolio governance profile exists, the risk cannot be reclassified to another domain through product interfaces; create a new correctly classified risk instead. This preserves the meaning of its review history.

## Derived state and reporting

The workspace and risk export expose profile-required, review-required, re-review-required, accepted, treatment/action-required, and review-overdue state. MCP risk detail responses discover the governance profile and review history through the existing governed entity interface.

## Limitations

The enterprise foundation does not provide risk aggregation, hierarchy, scenario simulation, capital modeling, or quantitative loss distributions. The operational foundation does not collect loss events, KRIs, process telemetry, or control indicators. The technology foundation does not discover assets, vulnerabilities, threats, or control telemetry. No domain automatically ingests verified evidence, closes issues, or triggers remediation. Third-party risk uses its separately documented workflow.

See the risk portfolio section of `docs/API_DOCUMENTATION.md` for endpoints and payload constraints. The read-only `Risk` MCP tool exposes the governed profile, mappings, review history, and issues to authorized managers, risk readers, and the assigned profile owner.
