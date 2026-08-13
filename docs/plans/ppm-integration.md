# GRC ↔ PPM integration

Cyber Audit keeps the POA&M (`RemediationProject` / `RemediationTask`). PPM keeps Gantt, members, timesheets, and portfolios. Neither side rebuilds the other.

## How they talk

| Direction | Path | What happens |
|---|---|---|
| GRC → PPM (command) | `POST /api/v1/projects`, `POST /api/v1/work-packages`, `POST /api/v1/entity-links` | POA&M → PPM project. Each remediation task → a **work card** (`work_package`) on that project's **board**. |
| GRC → PPM (signal) | `grc.remediation.published` / `grc.remediation.task_published` | PPM stores entity links. Replay is idempotent. |
| PPM → GRC (signal) | `project.updated` / `work_package.updated` (and deletes) | GRC projects `remote_status` on the link. It never writes POA&M or task status. Dragging a card on the PPM board updates the projection only. |

HMAC is the same envelope as the rest of the suite: `fynix-v2` + NUL-separated timestamp/event/source/webhook/delivery + body, header `X-Fynix-Signature: v2=…`.

## GRC config

```
SUITE_PPM_ENABLED=true
SUITE_PPM_BASE_URL=https://ppm.example.com
SUITE_PPM_TOKEN=pat_…
SUITE_PPM_TENANT_ID=<ppm tenant uuid>
SUITE_PPM_WEBHOOK_ID=<subscription uuid PPM will send>
SUITE_PPM_WEBHOOK_SECRETS=current,previous
MODULE_REMEDIATION_ENABLED=true
```

In PPM, subscribe a webhook to GRC `/api/suite/events` for `project.updated` and `project.deleted`. Add a suite inbound binding with `source: grc` so `grc.remediation.published` is applied.

## Operator

On a remediation project: **Open in PPM**. Second click reuses the existing link. Local create still succeeds if PPM is down.
