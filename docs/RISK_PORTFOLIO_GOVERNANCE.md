# Risk portfolio governance foundation

Fynix provides a shared governance workflow for risks classified as `enterprise`, `operational`, or `technology`. It extends the existing risk register, scoring, asset, implementation, policy, mitigation, REST, MCP, and export capabilities without claiming complete ServiceNow portfolio parity.

## Domain-aware profile

Users with `Manage Risk Portfolio` create or update a governance profile with an accountable owner, 1–25 appetite threshold, review frequency, next review date, and context notes. Each domain adds a required context:

- Enterprise risks identify the strategic objective exposed by the risk.
- Operational risks map an active business service.
- Technology risks map at least one active asset and at least one fully implemented implementation linked to an applicable control.

Accountable owners can discover and inspect only their assigned records in the read-only **Risk Management → Risk Portfolio** workspace. They cannot create profiles or reviews without the management permission.

## Enterprise hierarchy and score roll-up

Managers can place active, governed enterprise risks into a single-parent hierarchy. Parent assignment rejects self-links, cycles, ungoverned records, and non-enterprise domains; hierarchy writes are serialized and retried transactionally, and each change creates an attributable, append-only history record through product interfaces. Removing a parent promotes an active or inactive governed enterprise risk back to a portfolio root. Risks referenced by current or historical hierarchy records cannot be deleted through product interfaces, preserving that history.

For a selected root, Fynix traverses every descendant and derives a current exposure summary over active risks: risk and descendant counts, residual-score sum/average/maximum, count above each risk's appetite, and low/medium/high/critical score-band counts. Roll-ups are bounded to 100 hierarchy levels and 10,000 descendants; REST returns a validation response and UI/MCP return an explicit unavailable state when a bound is exceeded. The read-only workspace, export, REST response, and `Risk` MCP response expose the applicable hierarchy or roll-up state. Accountable owners can read aggregate metrics for a root they own even when descendants have other owners, but owner-only responses do not disclose those descendant identities, an unassigned parent's identity, or the privileged hierarchy-change history. Only `Manage Risk Portfolio` users can change the hierarchy; managers and `Read Risks` users can inspect full change history.

These are live aggregates of ordinal 1–25 risk scores for prioritization. They are not currency exposure, expected loss, probability distributions, correlation-adjusted exposure, or capital requirements. Inactive risks remain in the hierarchy but are excluded from the live aggregate.

## Deterministic enterprise stress scenarios

Managers can run a named, versioned stress scenario against an active governed enterprise hierarchy. They choose a narrative, 1–120 month horizon, qualitative probability band, and explicit likelihood/impact shifts from -4 through +4 for selected hierarchy risks. At least one shift must be non-zero. Unspecified active risks receive zero shift, and stressed likelihood and impact remain bounded to the same ordinal 1–5 scale.

The server serializes analysis with hierarchy changes, allocates the next version, and snapshots every active hierarchy risk's identity, parent, owner, appetite threshold, baseline residual values, applied shifts, rationale, and stressed values. It derives baseline and stressed score totals, delta, maximum, score-band counts, and the number above appetite. Scenario records and item snapshots are attributable and append-only through product interfaces. Current or historical scenario references prevent deleting a risk through product interfaces.

The read-only workspace, REST, export, and `Risk` MCP response expose scenario summaries. Managers and `Read Risks` users can open the workspace and inspect item snapshots; the operator modal is capped at 100 items and REST item detail is paginated at up to 100 items. MCP returns at most the latest 10 compact summaries without narratives plus a separate opt-in five-item detail page selected with `scenario` as `ID` or `ID:PAGE`; names, narrative, and rationales use byte-bounded excerpts. The complete scenario section is capped at 6,000 serialized bytes and reports when older summaries or item rows are omitted. REST scenario history is paginated at up to 100 records. The accountable owner of the root can inspect the aggregate summary but not foreign descendant identities, per-risk shifts, item snapshots, or manager attribution. A qualitative probability band is an operator classification, not a calibrated probability or statistical model.

## Attributable review

A manager records an `accepted`, `mitigate`, `transfer`, or `avoid` decision. The server snapshots the domain, inherent and residual scores, appetite threshold, asset and implementation identities and material values, operational service context, the governance profile, and a fingerprint of the material risk record. A risk above appetite cannot be accepted. Treatment decisions open a persistent governance issue.

Reviews are append-only through product interfaces and attributable to the reviewer. Current material risk, domain-context, mapping, or profile values that differ from the latest snapshot require another review. Mutable source records do not provide permanent field-level change history: reverting them to the reviewed snapshot restores the prior derived state. Evidence references are operator-supplied, unverified external text.

Once a portfolio governance profile exists, the risk cannot be reclassified to another domain through product interfaces; create a new correctly classified risk instead. This preserves the meaning of its review history.

## Derived state and reporting

The workspace and risk export expose profile-required, review-required, re-review-required, accepted, treatment/action-required, and review-overdue state. MCP risk detail responses discover the governance profile and review history through the existing governed entity interface.

## Limitations

The enterprise foundation does not provide stochastic/Monte Carlo simulation, calibrated probability distributions, correlation modeling, capital modeling, or quantitative loss distributions. The operational foundation does not collect loss events, KRIs, process telemetry, or control indicators. The technology foundation does not discover assets, vulnerabilities, threats, or control telemetry. No domain automatically ingests verified evidence, closes issues, or triggers remediation. Third-party risk uses its separately documented workflow.

See the risk portfolio section of `docs/API_DOCUMENTATION.md` for endpoints and payload constraints. The read-only `Risk` MCP tool exposes the governed profile, mappings, review history, scenario summaries, and issues within the authorization boundary described above.
