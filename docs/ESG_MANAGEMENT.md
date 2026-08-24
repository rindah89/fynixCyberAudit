# Governed ESG materiality management

Enable this foundation with `MODULE_ESG_MANAGEMENT_ENABLED=true`. It governs deliberately identified environmental, social, and governance topics, independent double-materiality assessments, linked goals, numeric KPIs, and attributable observations. It does not discover impacts, ingest ESG data automatically, calculate emissions, validate disclosures, or determine compliance with a reporting framework.

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

## Goals, KPIs, and performance observations

An authorized topic manager establishes one of at most 100 goals only when the latest exact topic version has an independent `Material` decision. Each goal retains its title, accountable owner, baseline/target dates, complete topic and assessment snapshots, creator/time, and SHA-256 fingerprint. A goal may contain at most 100 KPIs.

Each KPI defines a unit, increase/decrease direction, fixed-decimal baseline and target, deliberate measurement method and source reference, accountable owner, and one-to-365-day frequency. Its immutable definition fingerprint binds the complete goal snapshot. The target must move beyond the baseline in the declared direction.

An authorized KPI, goal, or topic owner—or an ESG manager—records at most 1,000 observations per KPI. Each append-only observation retains the exact KPI/goal/topic/assessment definition snapshot, fixed-decimal value, optional notes/source reference, actor/time, and SHA-256 fingerprint. The server derives only whether the configured target has been met and the next due time. A goal is `Achieved` only when every active KPI has met its configured target; otherwise an observed goal is `At Risk`. This is target comparison, not forecasting or verification of the measurement.

## Evidence boundary

The scores, stakeholder statements, framework references, methodology, KPI definitions, values, sources, and decisions are deliberate operator inputs. Their retention and fingerprints prove attributable record identity, not the truth, completeness, authenticity, or sufficiency of the underlying evidence. Fynix does not provide automatic ESG data collection, emissions calculation, trajectory forecasting, evidence or target validation, external assurance, automatic materiality analysis, disclosure generation, or reporting-framework compliance assurance.
