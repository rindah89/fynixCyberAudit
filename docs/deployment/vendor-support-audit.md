# Vendor support operation audit binding

FynixCyberAudit is the evidence ledger for privileged actions initiated from
`support.fynixhq.com`. The support control plane publishes only the signed
`support.operator.event` envelope. It never sends customer records, credentials,
backup contents, command output, or authentication tokens.

## Receiver configuration

Generate a dedicated webhook UUID, publisher tenant UUID, and at least 32 random
bytes for the HMAC secret. Do not reuse the ITSM or PPM binding.

```dotenv
SUITE_SUPPORT_ENABLED=true
SUITE_SUPPORT_WEBHOOK_ID=<dedicated UUID v4>
SUITE_SUPPORT_WEBHOOK_SECRETS=<dedicated random secret>
SUITE_SUPPORT_REMOTE_TENANT_ID=<support control-plane UUID>
SUITE_SUPPORT_REPLAY_TOLERANCE=300
SUITE_SUPPORT_LEDGER_KEY=<independent random secret of at least 32 bytes>
SUITE_SUPPORT_INTEGRITY_MAX_AGE=86400
```

Run migrations and verify `/api/suite/ready` reports:

```json
{"vendor_operations":{"enabled":true,"integrity":"ok"}}
```

The endpoint is the existing `POST /api/suite/events` signed Fynix v2 receiver.
Required headers are `X-Fynix-Signature`, `X-Fynix-Timestamp`,
`X-Fynix-Event`, `X-Fynix-Source`, `X-Fynix-Webhook-Id`, and
`X-Fynix-Delivery-Id`. The source is `support`; the event is
`support.operator.event`.

## Stored evidence

Each accepted event stores a bounded request UUID, operation correlation UUID, delivery UUID, operator
subject, action, target, outcome, source IP, optional ITSM change/incident,
before/after SHA-256 values, occurrence time, and bounded metadata. Rows are
append-only through the application model and form an HMAC-SHA-256 chain whose
key must be held outside the database. The ready endpoint reads the persisted
verification status in constant time. Schedule `php artisan
fynix:vendor-ledger-verify` at least once per `SUITE_SUPPORT_INTEGRITY_MAX_AGE`.

The HMAC chain detects database-only rewriting, but it is not an independent
retention boundary. Production promotion also requires exporting signed ledger
anchors to immutable storage such as an S3 Object Lock compliance-mode bucket.

Delivery UUIDs and request UUIDs are unique. Retries of an accepted delivery
return `duplicate ignored` without adding a second ledger row. Unknown sources
fail signature selection rather than inheriting another product's secret.

## Activation gate

Do not enable privileged portal mutations until a signed synthetic event is
visible in the ledger, a duplicate retry is idempotent, an invalid signature is
rejected, and the portal alerts when its durable outbound queue cannot drain.
