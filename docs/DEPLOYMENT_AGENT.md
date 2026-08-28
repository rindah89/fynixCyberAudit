# Deployment agent guide

> This is the canonical entry point for agents changing or deploying this repository. Historical design notes are not deployment authority.

## Suite context

Fynix is deployed as independently releasable applications behind one shared edge. A push to `main` deploys only this repository; never redeploy unrelated applications to ship this one.

| Application | Production URL | Repository |
|---|---|---|
| HQ / Executive selector | https://fynixhq.com | `rindah89/Fynix_Executive` |
| PPM | https://ppm.fynixhq.com | `rindah89/ppm` |
| HR | https://hr.fynixhq.com | `rindah89/fynixhrm` |
| Finance | https://finance.fynixhq.com | `rindah89/fynixFinance` |
| ITSM | https://itsm.fynixhq.com | `rindah89/itsm` |
| DocFlow | https://docflow.fynixhq.com | `rindah89/docflow` |
| CyberAudit | https://cyberaudit.fynixhq.com | `rindah89/fynixCyberAudit` |

Production currently runs in AWS account `172670236523`, region `us-east-2`, on application host `i-04578bd74b67567c1`. The governed local dispatcher builds and validates an immutable release bundle, then transports it through private S3 plus AWS Systems Manager; SSH and inbound access to the host are not required. GitHub Actions deployment is disabled.

The target on-prem architecture is the same contract: Linux + Docker Compose, persistent per-app `.env`, per-app databases/volumes, a shared HTTPS edge, immutable release artifacts, migrations, health checks, and rollback. Cloud transport may change, but application release scripts must remain usable without AWS-specific application logic.

## Rules for deployment agents

1. Read `.github/workflows/deploy-aws.yml` and the release/update scripts before changing deployment behavior.
2. Preserve persistent secrets and data. Never replace a production `.env` with an example file, print secrets, or commit credentials.
3. Test and build before deployment. Database changes must be additive/resumable and run with the repository's privileged migration path.
4. Push the intended commit to `main`, then invoke `scripts/deploy-aws-local.sh` only with the closed ITSM authorization receipt, current accepted CyberAudit evidence, completed soak evidence, and explicit `--execute`. The script remains fail-closed when its exact authorization adapter is unavailable.
5. Retain the dispatcher receipt and inspect the SSM command output on failure. Do not manually copy a working tree to production.
6. Verify the application-specific health URL and a user-facing route. A container being “Up” is insufficient.
7. Verify HQ still exposes the application in its selector when product visibility changes.
8. Roll back with the repository's release script/previous immutable artifact. Do not reset databases or delete volumes.
9. Never change DNS or Vercel domains to hide a failed origin. `fynixhq.com` DNS must resolve to the shared edge; TLS terminates there.
10. Keep AWS within the existing low-cost footprint. Adding infrastructure requires an explicit need and must remain reproducible for on-prem.

## Cross-application safety

Suite integrations use signed Fynix v2 events and dedicated service credentials. Authorization is the receiver's effective RBAC; do not assume a token's descriptive scope field is enforced. Do not share databases or application tokens. Changes to an event contract require compatible receivers before publishers are enabled. CyberAudit ↔ ITSM activation details live in CyberAudit's `docs/deployment/grc-itsm-one-dc.md`.

## Versioning, backup, and rollback

The production recovery objectives are: database RPO **1 hour**; files and configuration RPO **24 hours**; a verified recovery point before every deployment; individual-application RTO **2 hours**; and full-suite or on-premises recovery RTO **4 hours**. These are on-premises production objectives. The temporary AWS proof environment intentionally runs no scheduled or deployment-gated backups. On-premises backup schedules, monitoring, retention, restore drills, and deployment gates must demonstrate these limits rather than merely document them.

- The immutable production version is the full Git SHA release in the private bucket. Human releases use non-moving annotated SemVer tags (`vMAJOR.MINOR.PATCH`) pointing to a successfully deployed SHA.
- Before migrations and daily, create a consistent MySQL dump plus copies of persistent uploaded evidence and the production `.env`/application key. Encrypt backups off-host; retain at least 7 daily, 4 weekly, and 12 monthly recovery points unless policy is stricter.
- Roll back code with the retained exact bundle, prior SHA, and prior artifact SHA through `deploy/rollback.sh <bundle> <deploy-dir> <sha> <artifact-sha256>`. Laravel migrations must be additive/backward-compatible; never drop suite audit/link state in a routine rollback.
- Use `deploy/backup.sh` and `deploy/restore.sh` for a consistent MySQL plus application-storage recovery set. Provider snapshots may supplement it. After restore, verify migrations, `fynix:suite-preflight`, `/api/suite/ready`, evidence access, and an authenticated audit read.
- Run `deploy/rehearse-restore.sh <deploy-dir> <backup-archive> [evidence-dir]` at least quarterly. It restores the checksum-verified recovery set into a randomly named isolated database, mounts the restored application storage read-only, verifies migrations and suite preflight, removes the scratch database, and queues the exact report digest for independent CyberAudit review. A backup job alone is not recovery evidence.
- Configure `FYNIX_BACKUP_AGE_RECIPIENT`, `FYNIX_BACKUP_AGE_IDENTITY_FILE`, `FYNIX_SECRET_BACKUP_REF`, and protected off-host `FYNIX_BACKUP_S3_URI`, then run `sudo deploy/install-recovery-schedule.sh`. The installed timers create an encrypted backup daily and execute a fresh isolated restore drill on the first day of January, April, July, and October. Monitor failed units; a failed or unreviewed drill keeps DG-09 partial.
- Use `deploy/rollback.sh <extracted-release> <deploy-dir> <sha> <artifact-sha256>` for a previous immutable release. Perform and record a restore drill at least quarterly.


## Required handoff

Record the deployed commit, workflow URL/result, migration result, health result, backup/snapshot identifier (on-premises; record `not applicable` for the AWS proof environment), tested rollback artifact, and any configuration keys added (names only, never values). If deployment did not complete, say so explicitly.

## This repository

The governed local dispatcher creates the exact image/source bundle and calls `deploy/aws-update.sh <bundle> /opt/fynix-suite/cyberaudit <sha> <artifact-sha256>` on the host. The dispatcher is intentionally unusable until the closed `fynix-cyberaudit/deploy-release` ITSM adapter is present.

Production route: `https://cyberaudit.fynixhq.com/`. Integration readiness: `https://cyberaudit.fynixhq.com/api/suite/ready`. Run `php artisan migrate --force` and `php artisan fynix:suite-preflight` in the deployed app. The one-DC ITSM integration procedure is `docs/deployment/grc-itsm-one-dc.md`.
