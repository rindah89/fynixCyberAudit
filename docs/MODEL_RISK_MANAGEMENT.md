# Model risk management

The enterprise Model Risk Management foundation is enabled with `MODULE_MODEL_RISK_MANAGEMENT_ENABLED=true`. It governs non-AI analytical, statistical, financial, operational, compliance, and decision-support models separately from the AI Governance workflow.

## Roles and scope

- `Manage Model Risk` registers and revises inventory records.
- `Own Governed Models` sees and revises currently owned models.
- `Develop Governed Models` sees and revises currently assigned developments.
- `Validate Models` performs independent validation.
- `Read Model Risk` inspects the complete read-only register and history.

The service locks the current model, repeats authorization, and locks active owner/developer identities before each governed write. A validator must be separated from the owner, developer, and latest version author.

## Inventory and versions

Registration creates a server-numbered `Proposed` model with `Validation Required` governance state. Each immutable version retains model type, tier 1–4, lifecycle, owner/developer identity, intended use, methodology, input/output categories, assumptions, limitations, usage restrictions, implementation reference, change frequency, review date, actor/time, rationale, and SHA-256 fingerprint.

A material revision derives `Development` plus `Validation Required`. At most 100 versions are retained. Retirement is attributable, cannot rewrite material context, and is terminal through product interfaces.

## Independent validation

At most 100 validation reviews bind the exact latest material version. A review retains scope, testing performed, findings, performance and limitations assessments, decision, conditions, summary, validator/time, validity date, complete model snapshot, version identity, and SHA-256 fingerprint.

`Approved` moves the model to production/approved. `Conditionally Approved` requires explicit conditions and moves it to production/restricted. `Changes Required` returns it to development/validation-required; `Rejected` returns it to development/rejected. A material later change invalidates the current governance decision.

The displayed validation state becomes `Validation Expired` after the latest exact-version review's validity date passes; this derived state does not rewrite retained evidence.

REST histories validate pagination from 1 through 100 records. The operator workspace exposes the same inventory, version, validation, attribution, and fingerprint evidence.

## Limitations

Model definitions, tiers, tests, findings, performance statements, decisions, and references are deliberate inputs. Fynix does not discover models, execute model code, ingest performance/drift telemetry, validate datasets, calculate statistical metrics, benchmark automatically, prove conceptual soundness, validate regulatory compliance, manage source code or deployments, provide quantitative model-risk aggregation, or automate remediation. AI systems and use cases remain governed by the separate AI Governance workflow.
