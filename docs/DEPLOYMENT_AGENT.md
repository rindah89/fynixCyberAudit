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
| ITSM | https://itsm.fynixhq.com | `rindah89/itsm-suite` with private `rindah89/itsm` submodule |
| DocFlow | https://docflow.fynixhq.com | `rindah89/docflow` |
| CyberAudit | https://cyberaudit.fynixhq.com | `rindah89/fynixCyberAudit` |

Production currently runs in AWS account `172670236523`, region `us-east-2`, on application host `i-04578bd74b67567c1`. Builds run on the private self-hosted runner labeled `fynix-suite`. Release artifacts are immutable and reach the host through private S3 plus AWS Systems Manager; SSH and inbound access to the build host are not required.

The target on-prem architecture is the same contract: Linux + Docker Compose, persistent per-app `.env`, per-app databases/volumes, a shared HTTPS edge, immutable release artifacts, migrations, health checks, and rollback. Cloud transport may change, but application release scripts must remain usable without AWS-specific application logic.

## Rules for deployment agents

1. Read `.github/workflows/deploy-aws.yml` and the release/update scripts before changing deployment behavior.
2. Preserve persistent secrets and data. Never replace a production `.env` with an example file, print secrets, or commit credentials.
3. Test and build before deployment. Database changes must be additive/resumable and run with the repository's privileged migration path.
4. Push the intended commit to `main`. The GitHub Actions run is the deployment record; do not manually copy a working tree to production.
5. Wait for the workflow to complete successfully. Inspect the SSM command output on failure.
6. Verify the application-specific health URL and a user-facing route. A container being “Up” is insufficient.
7. Verify HQ still exposes the application in its selector when product visibility changes.
8. Roll back with the repository's release script/previous immutable artifact. Do not reset databases or delete volumes.
9. Never change DNS or Vercel domains to hide a failed origin. `fynixhq.com` DNS must resolve to the shared edge; TLS terminates there.
10. Keep AWS within the existing low-cost footprint. Adding infrastructure requires an explicit need and must remain reproducible for on-prem.

## Cross-application safety

Suite integrations use signed Fynix v2 events and dedicated service credentials. Authorization is the receiver's effective RBAC; do not assume a token's descriptive scope field is enforced. Do not share databases or application tokens. Changes to an event contract require compatible receivers before publishers are enabled. CyberAudit ↔ ITSM activation details live in CyberAudit's `docs/deployment/grc-itsm-one-dc.md`.

## Versioning, backup, and rollback

The production recovery objectives are: database RPO **1 hour**; files and configuration RPO **24 hours**; a verified recovery point before every deployment; individual-application RTO **2 hours**; and full-suite or on-premises recovery RTO **4 hours**. Backup schedules, monitoring, retention, restore drills, and deployment gates must demonstrate these limits rather than merely document them.

- The immutable production version is the full Git SHA release in the private bucket. Human releases use non-moving annotated SemVer tags (`vMAJOR.MINOR.PATCH`) pointing to a successfully deployed SHA.
- Before migrations and daily, create a consistent MySQL dump plus copies of persistent uploaded evidence and the production `.env`/application key. Encrypt backups off-host; retain at least 7 daily, 4 weekly, and 12 monthly recovery points unless policy is stricter.
- Roll back code by deploying the prior SHA artifact through its `deploy/aws-update.sh /opt/fynix-suite/cyberaudit <sha>`. Laravel migrations must be additive/backward-compatible; never drop suite audit/link state in a routine rollback.
- Use `deploy/backup.sh` and `deploy/restore.sh` for a consistent MySQL plus application-storage recovery set. Provider snapshots may supplement it. After restore, verify migrations, `fynix:suite-preflight`, `/api/suite/ready`, evidence access, and an authenticated audit read.
- Use `deploy/rollback.sh <extracted-release> <deploy-dir> <sha>` for a previous immutable release. Perform and record a restore drill at least quarterly.


## Required handoff

Record the deployed commit, workflow URL/result, migration result, health result, backup/snapshot identifier, tested rollback artifact, and any configuration keys added (names only, never values). If deployment did not complete, say so explicitly.

## This repository

The workflow builds the Laravel production image, creates an immutable source release, and runs `deploy/aws-update.sh /opt/fynix-suite/cyberaudit <sha>`.

Production route: `https://cyberaudit.fynixhq.com/`. Integration readiness: `https://cyberaudit.fynixhq.com/api/suite/ready`. Run `php artisan migrate --force` and `php artisan fynix:suite-preflight` in the deployed app. The one-DC ITSM integration procedure is `docs/deployment/grc-itsm-one-dc.md`.
