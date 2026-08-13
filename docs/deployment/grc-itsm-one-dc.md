# Cyber Audit ↔ ITSM deployment (one data center)

This runbook activates one Cyber Audit installation against one Fynix ITSM company. Repeat it with a new key, webhook UUID, secret, and numeric bindings for each later data center. The same commands work on AWS or on-prem Docker hosts.

## Prerequisites

- Deploy matching `main` releases of `rindah89/itsm-suite` and `rindah89/fynixCyberAudit`.
- Back up both databases.
- Confirm the two public HTTPS URLs resolve and the hosts can reach each other.
- Never reuse the API token or webhook secret between data centers.

## 1. Prepare ITSM

```bash
cd /opt/fynix-suite/itsm
docker compose exec itsm-app vendor/bin/phinx migrate
```

Create the analyst `svc-grc-sync` first, then the portal user `grc-integration@example.invalid`. Record their distinct numeric IDs. Record the numeric company, Cyber Audit origin, ticket type, department, and priority IDs.

Create one company-scoped `fitsm_` key bound to `svc-grc-sync`. Grant only:

- `tickets.create`
- `tickets.read`
- `ticket_notes.create`
- `cmdb_objects.read`
- `cmdb_ticket_links.create`

Do not grant `reference.read`, ticket update/delete, or administrative permissions. Save the returned key immediately; ITSM stores only its hash.

## 2. Configure Cyber Audit

Add these values to the persistent host `.env` used by Docker Compose:

```dotenv
SUITE_ITSM_ENABLED=true
SUITE_ITSM_BASE_URL=https://itsm.example.com
SUITE_ITSM_API_TOKEN=fitsm_REDACTED
SUITE_ITSM_COMPANY_ID=1
SUITE_ITSM_ORIGIN_ID=4
SUITE_ITSM_TICKET_TYPE_ID=2
SUITE_ITSM_DEPARTMENT_ID=3
SUITE_ITSM_PRIORITY_ID=3
SUITE_ITSM_SYNC_ANALYST_ID=9
SUITE_ITSM_REQUESTER_EMAIL=grc-integration@example.invalid
SUITE_ITSM_PUBLIC_URL=https://itsm.example.com
SUITE_GRC_PUBLIC_URL=https://cyberaudit.example.com
SUITE_ITSM_WEBHOOK_ID=<new UUID v4>
SUITE_ITSM_WEBHOOK_SECRET=<new random 32-byte secret>
```

Then converge the application:

```bash
cd /opt/fynix-suite/cyberaudit
docker compose up -d --build
docker compose exec app php artisan migrate --force
docker compose exec app php artisan fynix:suite-preflight
curl --fail https://cyberaudit.example.com/api/suite/ready
```

Preflight must exit zero before adding the subscription.

## 3. Subscribe ITSM

Create one active `fynix_v2` row in `webhook_subscriptions`:

- target: `https://cyberaudit.example.com/api/suite/events`
- events: `ticket_created`, `ticket_updated`, `ticket_resolved`, `ticket_assigned`
- webhook ID: the UUID configured in Cyber Audit
- secret: the same webhook secret configured in Cyber Audit
- publisher tenant UUID: a stable UUID for this ITSM deployment
- local tenant ID: the numeric ITSM company ID

The worker that drains `webhook_deliveries` must be running.

## 4. Verify

1. Run `php artisan fynix:suite-preflight` again.
2. Create a disposable Cyber Audit-linked ITSM ticket with all three `grc_entity_*` values.
3. Confirm the ticket has the Cyber Audit origin and opens the GRC deep link.
4. Assign and resolve it in ITSM.
5. Confirm `/api/suite/ready` reports the last ITSM inbound outcome and the corresponding `suite_entity_links` projection changes to closed.
6. Confirm an unsigned or incorrectly signed event returns 401.

## Rollback

Set `SUITE_ITSM_ENABLED=false`, recreate the Cyber Audit app container, and deactivate the ITSM webhook subscription and API key. Do not drop the additive columns or suite delivery/link tables; they are audit and replay evidence.
