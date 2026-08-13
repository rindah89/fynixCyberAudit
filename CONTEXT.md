# CONTEXT.md — Fynix Cyber Audit

Operating brief for engineers and agents. Product language comes from the OpenGRC documentation at [docs.opengrc.com](https://docs.opengrc.com). This repository is the Fynix Cyber Audit fork: same GRC domain, Fynix HQ identity, Fynix design, local content packs.

Do not invent a second product. If a term is defined here, use it.

---

## What this is

Fynix Cyber Audit is a cyber Governance, Risk, and Compliance application for small and mid-sized teams. It is meant to be learnable in a day: import a framework, describe how you implement the controls, audit them, and produce evidence for an assessor.

It is **not** an enterprise GRC suite (no rule engine, no ServiceNow-scale CMDB). It **is** the operator console for:

- Standards → Controls → Implementations (the compliance spine)
- Internal and external audits, data requests, IRL import
- Risk register with inherent / residual scoring and heatmaps
- Policies, assets, applications
- Vendor TPRM (surveys, documents, vendor portal)
- Public Trust Center (document sharing with approval)
- Recurring checklists
- REST API + MCP for AI clients

Upstream product reference: [docs.opengrc.com](https://docs.opengrc.com). Visual system: `design.md`. Install/dev: `DEVELOPMENT.md`.

---

## Domain model (required reading)

This graph is the product. Everything else hangs off it.

```
Program (optional container)
  └── Standard          e.g. NIST 800-171r3, TSC 2017
        └── Control     e.g. 03.01.01 Account Management
              └── Implementation   e.g. GPO auto-logout after 15 min
              └── Implementation   e.g. SSH ClientAliveInterval 900
```

| Term | Meaning |
|---|---|
| **Standard** | A framework or set of requirements (ISO 27001, PCI DSS, 800-171, SOC 2 TSC). |
| **Control** | A safeguard in that standard. Typed (administrative / operational / technical), categorized (preventive, detective, corrective, …), enforced as mandatory or addressable. |
| **Implementation** | How *this* organization actually does the control. One implementation can satisfy many controls. |
| **Program** | Optional grouping of standards/controls for a initiative (CMMC) or function (Physical Security). Not a second copy of the data — a live container. |
| **Audit** | Systematic review of a standard, of implementations, or of a program. Items are Not Assessed / Compliant / Partially Compliant / Non-Compliant / Not Applicable. |
| **Data request** | Ask a control owner for evidence; response is stored and linked to the audit item. |
| **IRL** | Information Request List from an *external* auditor. Import it, turn rows into data requests, export a hashed evidence ZIP. |
| **Risk** | Scenario scored as likelihood × impact (1–5 each → 1–25). **Inherent** = no controls. **Residual** = after linked implementations. Treatment: open / avoid / mitigate / transfer / accept. |
| **Policy** | Organizational document (policy / procedure / standard) with lifecycle, revisions, and links to controls, implementations, and risks. |
| **Vendor** | Third party. Status pending → accepted / rejected / expired / terminated. Organizational rating + assessed score from surveys. |
| **Survey** | Vendor (or internal) questionnaire. Weighted scoring → 0–100 → Very Low … Critical. |
| **Trust Center** | Public page at `/trust`. Public docs download freely; protected docs need request + approval + signed magic link. |

Source: [Key Terms](https://docs.opengrc.com/foundations/key-terms/), [Workflows](https://docs.opengrc.com/foundations/workflows/), [Audits](https://docs.opengrc.com/foundations/audits/).

---

## Typical workflows

**Compliance.** Create/import a Standard → review Controls → write Implementations → Audit.

**Internal audit.** Scope a standard / program / implementations → assess each item → notes + evidence → report → remediate.

**External audit.** Import the IRL → spawn data requests → owners fulfill → review → export evidence package (PDFs + ZIP + hashes).

**Risk.** Create the scenario → inherent L×I → attach implementations → residual L×I → heatmap + PDF report.

**Vendor.** Add vendor → send survey (or score internally) → collect documents → accept/reject. Vendor users live on the **portal**, not staff OIDC.

**Trust Center.** Publish certs + docs → visitor requests protected files (NDA optional) → staff approve → 24h magic link.

---

## Surfaces (this repo)

| Surface | Path | Audience | Auth |
|---|---|---|---|
| App panel | `/app` | GRC operators | Local password and/or Fynix HQ OIDC |
| Admin panel | `/admin` | Super-admins | Same staff identity |
| Vendor portal | `/portal` | Vendor respondents | Vendor users, magic links — **not** staff OIDC |
| Trust Center | `/trust` | Public + approved requesters | None / signed access link |
| REST API | `/api` | Integrators | Sanctum |
| MCP | `/mcp/fynixcyberaudit` | AI clients | OAuth `mcp:use` |

Light mode is forced (`ThemeMode::Light`, `darkMode(false)`). Design tokens live in `resources/css/tokens.css`. Do not restore GRC-blue or Bruno Ace.

---

## Architecture (how we build here)

Stack: Laravel 12, Filament 4, Livewire 3, MySQL (Docker) or SQLite (installer), Vite, Manrope + JetBrains Mono.

**Seams that already exist — use them, do not bypass:**

| Module | Path | Rule |
|---|---|---|
| Vendor access | `app/Access/VendorAccess.php` | Unsigned survey tokens do not register or set passwords. |
| File access | `app/Access/FileAccess.php` | Private media and attachments go through ACL, not raw IDs. |
| Data-request fulfillment | `app/Access/DataRequestFulfillment.php` | Who may respond, and when. |
| Identity | `app/Identity/*` | OIDC authorization-code, CSRF state, iss/aud/sub, `email_verified` fail-closed, sub-first linking. Never adopt a local password account by email. Trust Center and vendor portal stay off this path. |
| Bundles | `app/Support/LocalBundleCatalog.php` | Packs come from `resources/bundles/`. No `repo.opengrc.com`. |
| Palette | `app/Support/FynixPalette.php` | Filament colors. |

`Model::unguard()` is gone. Writes go through `$fillable`. API `show` authorizes the instance; do not reintroduce `?with=` relation loaders.

Artisan namespace is `fynix:*` (`fynix:install`, `fynix:create-user`, `fynix:deploy`). Docker image/project is `fynixcyberaudit`. Compose reads `.env` (see `.env.docker.example`).

---

## Content packs

Shipped locally (`resources/bundles/catalog.json` + seeders / JSON):

- TSC 2017 (SOC 2 Trust Services Criteria)
- HIPAA Security Rule
- NIST SP 800-171 r2 / r3
- NIST SP 800-53 Low
- CMMC 2.0 Level 2
- CIS Controls

Add a pack by extending the catalog and a JSON file or seeder. Do not point `general.repo` at a remote OpenGRC catalog.

---

## Identity

Staff: Fynix HQ OIDC (authorization code). Config: `config/oidc.php`, env `OIDC_*`. Settings UI: Admin → Authentication.

Vendor portal and Trust Center are **not** HQ SSO. Keep that split.

Break-glass local passwords exist only when `OIDC_ENFORCE_SSO_ONLY` is false and `IdentityService::assertLocalPasswordAllowed` agrees.

---

## Permissions (shape)

Spatie roles. Typical cuts:

- Super Admin — everything
- Security Admin — operate GRC, limited delete
- Internal Auditor — read + create/read audits
- Regular User — list/read
- Trust: `Manage Trust Center` vs `Manage Trust Access` (approve requests only)
- Vendor: `Manage Vendor Management` for configuration

Check the matching `app/Policies/*` and Spatie permission names before adding a new verb.

---

## API and MCP

REST: Sanctum, permission-aware. Documented in `docs/API_DOCUMENTATION.md` and [docs.opengrc.com/api](https://docs.opengrc.com/api/).

MCP: `App\Mcp\Servers\FynixCyberAuditServer`, route `POST /mcp/fynixcyberaudit`. Manage* tools: list / get / create / update / delete. Enable in Admin → AI Settings. See `docs/mcp-server.md`.

---

## What not to do

- Do not treat vendor portal users as staff Users.
- Do not fetch content from `repo.opengrc.com`.
- Do not use `#75FC96` as text on white (fill only).
- Do not reintroduce a second brand hue or dark-by-default chrome.
- Do not unguard models or accept `?with=` on API show.
- Do not host this software as a multi-tenant SaaS for customers — upstream license forbids hosting for others.

---

## Where to look

| Need | Path |
|---|---|
| Product requirements | `PRD.md` |
| Visual rules | `design.md`, `resources/css/tokens.css` |
| Local install | `DEVELOPMENT.md`, `php artisan fynix:install` |
| Docker | `docker-compose.yml`, `.env.docker.example` |
| Filament resources | `app/Filament/Resources/*` |
| Access / identity | `app/Access/*`, `app/Identity/*` |
| Upstream user docs | https://docs.opengrc.com |
