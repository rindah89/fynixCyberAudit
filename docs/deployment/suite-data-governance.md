# Suite data-governance oversight

Cyber Audit is the assurance authority for Fynix Suite Data Governance Controls. Products remain systems of record and enforce controls locally. They publish signed, bounded Governance Statements; Cyber Audit never receives application credentials, raw sensitive records, or direct database access.

## Contract

- Schema: `docs/contracts/fynix-governance-evidence-v1.schema.json`
- Receiver: `POST /api/suite/governance/evidence`
- Public operational coverage: `GET /api/suite/governance/ready`
- Permissioned report: `GET /api/governance/oversight` with `View Governance Oversight`
- Envelope: Fynix v2 HMAC headers, one dedicated webhook ID and secret per source
- Maximum body: 64 KiB
- Freshness objective: 26 hours by default
- Active monitoring: `php artisan fynix:monitor-governance --notify`, scheduled hourly by the Cyber Audit scheduler

The required sources are `hq`, `ppm`, `hr`, `finance`, `itsm`, `docflow`, `devops`, `office`, and `cyberaudit`. A source is conformant only when its binding is enabled, its latest statement is current, all 12 controls are present, and it has no open exceptions. `unknown` is an exception, not a passing state.

Evidence references are identifiers or links to evidence held by the source application. They must never contain passwords, tokens, personal records, document contents, payroll values, or other sensitive payloads.

## Configuration

Set names only are documented here; never commit or print values.

```dotenv
SUITE_GOVERNANCE_REQUIRED_SOURCES=hq,ppm,hr,finance,itsm,docflow,devops,office,cyberaudit
SUITE_GOVERNANCE_FRESHNESS_HOURS=26
SUITE_GOVERNANCE_BINDINGS_JSON=<secret JSON object>
```

The bindings JSON is keyed by source. Each value contains `enabled`, `tenant_id`, `webhook_id`, `secret`, and optionally `replay_tolerance`. When the JSON value is empty, Cyber Audit builds the same binding registry from each application's prefixed `*_GOVERNANCE_TENANT_ID`, `*_GOVERNANCE_WEBHOOK_ID`, and `*_GOVERNANCE_SECRET` variables. Kubernetes uses this second form: External Secrets combines the nine dedicated AWS Secrets Manager records into `fynix-cyberaudit-governance-bindings`. Generate a distinct random secret and UUID webhook ID for every application and environment. Do not reuse ITSM, PPM, application API, database, or OIDC credentials.

## Publisher register

| Source | Daily command | Secret prefix |
|---|---|---|
| `hq` | `npm run governance:publish` | `HQ_GOVERNANCE_` |
| `ppm` | `ppm-publish-governance` | `PPM_GOVERNANCE_` |
| `hr` | `php scripts/publish-governance.php` | `HR_GOVERNANCE_` |
| `finance` | `npm run governance:publish` | `FINANCE_GOVERNANCE_` |
| `itsm` | `composer governance:publish` | `ITSM_GOVERNANCE_` |
| `docflow` | `docflow-publish-governance` | `DOCFLOW_GOVERNANCE_` |
| `devops` | `fynix-devops-publish-governance` | `DEVOPS_GOVERNANCE_` |
| `office` | `npm run governance:publish` | `OFFICE_GOVERNANCE_` |
| `cyberaudit` | `php artisan fynix:publish-governance` | `CYBERAUDIT_GOVERNANCE_` |

Every prefix expands to `ENDPOINT`, `TENANT_ID`, `WEBHOOK_ID`, and `SECRET`. The application deployment authority documents its own command and scheduling boundary; this register is the Cyber Audit completeness checklist.

## Safe activation order

1. Back up Cyber Audit according to `docs/DEPLOYMENT_AGENT.md`.
2. Deploy the compatible Cyber Audit receiver and run `php artisan migrate --force`.
3. Run `php artisan db:seed --class=RolePermissionSeeder` to add the oversight permission to the existing assurance roles.
4. Add the Cyber Audit binding configuration without replacing the persistent `.env`.
5. Verify `/api/suite/governance/ready` reports every required source explicitly; missing is expected before publishers are activated.
6. Deploy and test one publisher at a time with a new per-source secret. Confirm wrong tenant, wrong signature, stale signatures, duplicate delivery, statement-ID conflicts, and oversized body denials.
7. Enable its production schedule only after the receiver records a valid statement.
8. Assign owners and due dates to every resulting exception. Never change `unknown` or `ineffective` to `effective` merely to make the dashboard green.

Verify the container cron invokes `php artisan schedule:run` every minute. The hourly monitor sends a database notification to every user holding `View Governance Oversight` whenever the state changes, repeats unresolved state after 24 hours, and exits non-zero while evidence is missing, stale, or has open exceptions. Forward scheduler failures to the platform alert channel.

Cyber Audit is not exempt from the baseline. Provision the dedicated `CYBERAUDIT_GOVERNANCE_ENDPOINT`, `CYBERAUDIT_GOVERNANCE_TENANT_ID`, `CYBERAUDIT_GOVERNANCE_WEBHOOK_ID`, and `CYBERAUDIT_GOVERNANCE_SECRET`, then schedule `php artisan fynix:publish-governance` daily. The receiver binding for source `cyberaudit` must use the same tenant, webhook ID, and secret. Alert on any non-zero exit.

Rollback application code with the retained immutable artifact. Do not reverse or delete the governance tables during routine rollback; statements and exception history are audit evidence.
