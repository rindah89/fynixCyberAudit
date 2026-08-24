# Governed ESG materiality management

Enable this foundation with `MODULE_ESG_MANAGEMENT_ENABLED=true`. It governs deliberately identified environmental, social, and governance topics and independent double-materiality assessments. It does not discover impacts, ingest ESG data, calculate emissions, manage KPI performance, validate disclosures, or determine compliance with a reporting framework.

## Roles and access

- `Manage ESG` registers topics, revises any current topic, retires topics, and may assess topics when the independence rules permit it.
- `Own ESG Topics` users inspect and revise only topics assigned to them.
- `Assess ESG` users inspect all topics and record independent materiality assessments.
- `Read ESG` users inspect all topics and their complete retained history.

The topic owner and the author of the latest topic version cannot assess that version. The service repeats current authorization after locking the topic; direct service calls and REST calls share the same boundary.

## Material-topic evidence

Registration assigns a server-owned `ESG-{year}-{sequence}` code and retains the first immutable version. Each version contains the complete material topic context: pillar, description, outward-impact context, financial-risk context, opportunity context, stakeholder groups, reporting-framework references, organizational boundary, source reference, accountable owner, review date, attribution, time, and SHA-256 fingerprint.

Material revisions append a new version and derive `Review Required`. Assessment state and review scheduling are server-owned. Retirement is attributable, versioned, and terminal. A topic permits at most 100 versions.

## Independent materiality assessment

An assessor scores impact materiality and financial materiality from one through five, describes the stakeholder evidence and methodology, and deliberately records `Material`, `Not material`, or `Changes required`. The server binds the assessment to the exact latest retained topic version, records its complete topic snapshot, decision summary, assessor/time, next review date, and SHA-256 fingerprint, and derives the topic status. Changed current material context cannot be assessed against a stale version. A topic permits at most 100 assessments.

REST provides maintenance and paginated history. The operator workspace is read-only and exposes the complete topic, version, and assessment evidence. Routine rollback retains the governance tables.

## Evidence boundary

The scores, stakeholder statements, framework references, methodology, and decisions are deliberate operator inputs. Their retention and fingerprints prove attributable record identity, not the truth, completeness, authenticity, or sufficiency of the underlying evidence. Fynix does not yet provide ESG goals/KPIs, observation ingestion, emissions accounting, target validation, external assurance, automatic materiality analysis, disclosure generation, or reporting-framework compliance assurance.
