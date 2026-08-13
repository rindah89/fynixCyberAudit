# PRD.md — Fynix Cyber Audit

Product requirements for Fynix Cyber Audit, the Fynix suite GRC / audit application.

Capability definitions are taken from the OpenGRC documentation at [docs.opengrc.com](https://docs.opengrc.com) and restated for this product. Where this fork differs (identity, design, content distribution), this file wins.

Related: `CONTEXT.md` (engineering brief), `design.md` (visual system), `DEVELOPMENT.md` (install).

---

## 1. Problem

Small and mid-sized security teams need to:

1. Adopt a public framework (NIST, SOC 2, HIPAA, CMMC, CIS) without retyping it.
2. Record how they *actually* implement each control.
3. Audit themselves before an assessor arrives.
4. Collect evidence from control owners and hand a clean package to an external auditor.
5. Keep a risk register that talks to those implementations.
6. Assess vendors and share security packets with customers.

Enterprise GRC platforms solve this at a cost and complexity that those teams cannot absorb. Fynix Cyber Audit exists so that work is not a spreadsheet and not a six-month implementation.

---

## 2. Who it is for

| Persona | Job | Primary surfaces |
|---|---|---|
| GRC / security operator | Own the program day to day | `/app` |
| Internal auditor | Assess controls, request evidence | `/app` audits + data requests |
| External assessor (indirect) | Receives IRL responses and evidence ZIP | Export only — no login required |
| Control / system owner | Answer data requests and checklists | `/app` (staff) |
| Vendor respondent | Complete surveys, upload docs | `/portal` |
| Customer / partner | Request SOC 2, pen-test, policies | `/trust` |
| Super-admin | Users, roles, SSO, storage, AI | `/admin` |
| AI / integration | Automate list/get/create on GRC entities | REST API, MCP |

**Not for:** hosting this app as a commercial multi-tenant GRC for other companies. Upstream license forbids hosting for customers.

---

## 3. Product thesis

A **quiet control room**. One mint accent. Status color only when it means RAG. Light chrome. The domain model is small and strict:

**Standard → Control → Implementation**, optionally grouped in a **Program**, assessed in an **Audit**, evidenced by **Data Requests**, reduced via **Risks** linked to those implementations.

If a feature does not sit on that spine, it is secondary.

---

## 4. Core requirements

### 4.1 Compliance spine

**Must:**

- Import and maintain Standards (code, name, authority, status).
- Maintain Controls under a standard: type, category, enforcement, description, discussion, test procedure, owner.
- Maintain Implementations that map many-to-many to controls, and can also attach to assets, applications, vendors, policies, and risks.
- Optional Programs that group standards and/or loose controls for an initiative (e.g. CMMC) or a function (e.g. Physical Security) without forking the live records.

**Shipped frameworks** (local catalog, not a remote OpenGRC repo): TSC 2017, HIPAA Security Rule, NIST 800-171 r2/r3, NIST 800-53 Low, CMMC 2 L2, CIS Controls.

**Must not:** download packs from `repo.opengrc.com`. New packs are files under `resources/bundles/`.

### 4.2 Audits

From [Audits](https://docs.opengrc.com/foundations/audits/):

| Type | Scope |
|---|---|
| Standards audit | All controls in one standard |
| Implementation audit | Implementations, not the abstract control |
| Program audit | Everything in a program (multi-standard) |

Each audit item: Not Assessed | Compliant | Partially Compliant | Non-Compliant | Not Applicable, plus notes.

**Data requests:** create, assign, notify, collect files/text, link to the audit item. Fulfillment rules live in `app/Access/DataRequestFulfillment.php`.

**IRL:** import a CSV Information Request List; track each row; export an evidence package (PDFs, ZIP, per-file hashes).

**Reports:** PDF audit report, CSV, evidence download.

AI-powered bulk assessment of an in-progress audit is an optional Enterprise-shaped capability (settings + AI provider). It is not required for an audit to complete.

### 4.3 Risk

From [Risk Management](https://docs.opengrc.com/features/risk-management/):

- Likelihood and impact 1–5. Score = L × I (1–25).
- Inherent (no controls) and residual (after linked implementations).
- Status: Not Assessed | In Progress | Assessed | Closed.
- Treatment: Open | Avoid | Mitigate | Transfer | Accept.
- 5×5 heatmaps for inherent and residual; click-through filter.
- PDF risk report with both maps and a table of implementations.
- Links to implementations, policies, and assets.

Color bands: 1–4 green, 5–8 blue, 9–12 amber, 13–17 high, 18–25 red. Never color-alone — always pair with a label.

### 4.4 Policies

From [Policies](https://docs.opengrc.com/features/policies/):

- Lifecycle: Draft → In Review → Awaiting Feedback → Pending Approval → Approved → Archived / Superseded / Retired.
- Types: Policy, Procedure, Standard.
- Code, owner, department, scope, effective/retired dates, rich-text purpose/scope/body, optional file, revision history.
- Attach to controls, implementations, risks, and exceptions.

### 4.5 Assets

From [Asset Management](https://docs.opengrc.com/features/assets/):

- Inventory of hardware, licenses, and equipment: tag, serial, type, status, specs, assignment, location, finance, warranty, lifecycle, encryption/AV, data classification.
- Parent/child assets.
- Link to implementations and risks.

### 4.6 Applications

From [Applications](https://docs.opengrc.com/features/applications/):

- Software inventory: name, owner, type (SaaS / Desktop / Server / Appliance / Other), status (Approved / Rejected / Limited / Expired).
- **Every application must have a vendor.** That is the TPRM join.
- Link to implementations.

### 4.7 Vendor TPRM

From [Vendor Risk Management](https://docs.opengrc.com/features/vendor-management/):

- Vendor profile, manager, contacts, status, organizational rating, assessed score.
- Surveys: internal or send-to-vendor (email + magic link). Boolean / text / choice / file. Weighted score 0–100 → Very Low … Critical.
- Documents (SOC 2, ISO, pen-test, insurance, BCP, policies, contract, SLA) with review status and expiry.
- Vendor portal (`/portal`): complete surveys, upload documents. **Vendor users are not staff Users and do not use Fynix HQ OIDC.**
- Access control: `app/Access/VendorAccess.php`. Unsigned tokens must not register accounts or set passwords.

### 4.8 Trust Center

From [Trust Center](https://docs.opengrc.com/features/trustcenter/):

- Public site at `/trust`: branding, content blocks, certification badges, public downloads, protected request form (honeypot + optional NDA).
- Staff approve/reject; approved requesters get a signed, expiring magic link (default 24h).
- Permissions: Manage Trust Center vs Manage Trust Access.
- Public Trust Center stays off staff OIDC.

### 4.9 Checklists

From [Checklists](https://docs.opengrc.com/features/checklists/):

- Templates (Draft / Active / Archived) with items: yes/no, short/long text, single/multi choice, file.
- Recurrence: daily / weekly / monthly / quarterly / yearly.
- Checklist instance: assign, due date, fill, submit. Status Not Started / In Progress / Completed.
- Optional approval with signature.
- Once a checklist exists from a template, template items lock.

### 4.10 Identity and admin

- Staff authenticate on `/app` and `/admin` with local password and/or **Fynix HQ OIDC** (authorization code, CSRF state, iss/aud/sub, `email_verified` fail-closed, sub-first linking). Never bind a local password user by email match alone.
- `OIDC_ENFORCE_SSO_ONLY` blocks local passwords except break-glass.
- Spatie roles and permissions; session timeout; inactivity guard.
- Storage: private disk or S3/Spaces. Files through `FileAccess`, not raw object IDs.
- Mail: SMTP from settings; templates for user create, password reset, evidence request, vendor invite, Trust Center.
- AI settings: provider/key/quotas, MCP enablement. Default MCP endpoint `/mcp/fynixcyberaudit`.

### 4.11 API and automation

- REST under `/api` with Sanctum. Authorize the instance. No `?with=` relation loaders.
- MCP Manage* tools: list / get / create / update / delete for the GRC entities.
- Queue worker required in production for mail, imports, scheduled checklists, evidence export.

---

## 5. Non-functional

| Area | Requirement |
|---|---|
| Stack | Laravel 12, Filament 4, Livewire 3, PHP 8.4, MySQL or SQLite |
| UI | Light mode default (dark mode off). PPM/Fynix tokens. Manrope + JetBrains Mono. See `design.md`. |
| Deploy | `php artisan fynix:install` or Docker Compose (`fynixcyberaudit` image). Config from `.env`. |
| Security | CSRF, policies, signed vendor/trust links, hashed evidence, WAF recommended if internet-facing. |
| License | CC BY-NC-SA 4.0 with upstream exceptions (no resale of the code, no hosting for customers). |

---

## 6. Success

A new team can, in one working session:

1. Install (SQLite unattended or Docker + `.env`).
2. Load TSC 2017 (or another local pack).
3. Write three implementations and map them to controls.
4. Open a standards audit and mark items.
5. File one risk with inherent and residual scores.
6. Invite a vendor *or* publish one Trust Center document.

If any of those six fail, the product failed its brief.

---

## 7. Out of scope (this product)

- Multi-tenant SaaS for other companies.
- Remote OpenGRC content marketplace.
- Replacing Fynix PPM, ITSM, Finance, or HR.
- Dark-first chrome or a second brand hue.
- Treating vendor-portal or Trust Center visitors as HQ employees.

Enterprise-shaped extras documented upstream (incident module, project management, dedicated Surveyor/Risk Assessor products) are **not** required for Fynix Cyber Audit v1 unless implemented in this repo.

---

## 8. Source of truth

| Topic | Document |
|---|---|
| How the domain works | [docs.opengrc.com/foundations/key-terms](https://docs.opengrc.com/foundations/key-terms/) |
| How work is done | [docs.opengrc.com/foundations/workflows](https://docs.opengrc.com/foundations/workflows/) |
| Feature how-tos | [docs.opengrc.com/features](https://docs.opengrc.com/features/risk-management/) |
| This fork’s engineering rules | `CONTEXT.md` |
| Look and feel | `design.md` |
