# IRM capability evidence ledger

This ledger is the authority for comparing Fynix Cyber Audit with the broader [ServiceNow Integrated Risk Management portfolio](https://www.servicenow.com/products/integrated-risk-management.html). It separates implemented behavior from roadmap intent and prevents unsupported parity claims.

## Claim policy

A capability may be described as **implemented** only when this ledger points to all applicable product evidence:

1. a persisted domain model and migration;
2. an authorized operator, REST, or MCP interface;
3. an automated behavior test; and
4. operator documentation for workflows that require it.

Code presence alone is not enough to claim portfolio parity. `docs/plans/` is roadmap material, not product evidence. Partial capabilities must be named narrowly. Missing capabilities must not appear in product or sales copy as available.

## Current defensible product claim

Fynix Cyber Audit implements workflows for **risks, controls, audits, findings, evidence, and remediation**. These workflows can support an IRM program, but Fynix does not currently claim full ServiceNow IRM portfolio parity.

## Portfolio matrix

| Portfolio area | Evidence status | Evidence in this repository | Allowed wording |
|---|---|---|---|
| Enterprise risk | Partial | The governed risk register can classify, persist, validate, filter, and export `RiskDomain::Enterprise`. REST creation and score invariants are covered by `tests/Feature/RiskDomainTest.php`; end-to-end operator, MCP, and reporting evidence is incomplete. | Do not make a portfolio-level enterprise-risk claim yet. A narrow product note may say “Classify risks as enterprise risks.” |
| Operational risk | Partial | The governed risk register can classify, persist, validate, filter, and export `RiskDomain::Operational`; end-to-end operator, MCP, and reporting evidence is incomplete. | Do not make a portfolio-level operational-risk claim yet. A narrow product note may say “Classify risks as operational risks.” |
| Technology risk | Partial | Risks can be deliberately classified as `technology` and linked to assets, controls through implementations, policies, and mitigations. Existing records remain unclassified until an operator reviews them. End-to-end reporting evidence for the domain is incomplete. | Continue the existing “manage risks” claim; do not promote it to technology-risk-management parity. |
| Third-party risk | Partial | Vendor scoring, surveys, documents, and portal workflows are implemented, and risks can be classified as `RiskDomain::ThirdParty`. A governed vendor-to-risk workflow and end-to-end reporting evidence are incomplete. | “Assess vendors” is supported. Do not claim third-party risk management or continuous third-party monitoring yet. |
| Policy and compliance | Implemented foundation | Policies have discrete obligations, accountable owners, optional control mappings, due/overdue state, append-only attestation history through product interfaces, operator-supplied external evidence references, and active same-policy exceptions. Operator and integration workflows are documented in `docs/POLICY_COMPLIANCE.md`; behavior and authorization are covered by `tests/Feature/PolicyComplianceTest.php`. Regulatory content feeds, employee acknowledgement campaigns, verified evidence relations, and automated policy-to-law change detection are absent. | “Manage policy obligations and attributable owner or authorized-manager attestations.” Do not claim end-to-end policy compliance automation, verified evidence linkage, or regulatory change management. |
| Continuous control testing | Not yet evidenced | Recurring checklists and control effectiveness fields are useful inputs, but no documented automated indicator ingestion, test engine, failure threshold, or continuous-monitoring workflow exists. | Do not claim continuous control testing or monitoring. |
| Audit management | Implemented | `Audit`, `AuditItem`, data requests, IRL import, reports, policies, REST interface, and feature tests including `AuditTest`, `AuditItemTest`, and `DataRequest*Test` | “Plan and perform audits, record findings, request evidence, and report results.” |
| Findings | Implemented | Audit items record applicability, effectiveness/compliance status, auditor notes, and related evidence; remediation tasks can retain the originating audit item | “Track audit findings and link them to remediation.” |
| Evidence | Implemented | Data-request responses, private file access, audit attachments, evidence exports, authorization records, and change-evidence acceptance tests | “Collect, authorize, retain, and export audit evidence.” Avoid claiming evidence types not covered by a named workflow. |
| Remediation | Implemented foundation | `RemediationProject`, `RemediationTask`, membership policy, audit-item link, resource UI, and `tests/Feature/RemediationTest.php` | “Manage remediation projects and tasks linked to findings.” Do not claim PPM portfolio management. |
| Operational resilience | Partial | Incident workflows, applications/assets, recovery controls, and suite integration exist, but business-impact analysis, dependency maps, scenario exercises, recovery plans, and resilience metrics are not documented as one verified workflow. | “Support incident response and recovery-control evidence.” Do not claim operational resilience management. |
| AI governance | Not yet evidenced | AI provider settings, quota controls, audit logging, and assisted workflows are platform controls. There is no AI system inventory, AI risk taxonomy, model assessment, approval lifecycle, or regulatory mapping. | “Govern use of Fynix AI features with quotas and audit records.” Do not claim AI governance. |

## Parity sequence

Work advances by closing one evidence-complete vertical slice at a time:

1. **Risk portfolio foundation** — explicit enterprise, operational, technology, and third-party domains are now persisted and exposed in UI/REST/export; complete MCP, report, operator-workflow, and behavior-test evidence remains.
2. **Policy compliance foundation** — delivered: policy obligations, attributable attestations, exceptions, control mappings, due/overdue status, append-only history through product interfaces, operator UI, REST interface, and documentation. Broader regulatory-change and acknowledgement capabilities remain.
3. **Continuous control testing** — test definitions, scheduled/manual executions, immutable results, thresholds, findings, and remediation triggers.
4. **Operational resilience** — business services, impact analysis, dependency mapping, plans, exercises, recovery objectives, and issues.
5. **AI governance** — AI system inventory, use cases, owners, risk assessments, controls, approvals, monitoring, and evidence.

Each slice updates this ledger in the same commit as its behavior and tests. Product copy changes only after the row reaches **Implemented**.
