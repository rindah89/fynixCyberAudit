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

Suite integrations use signed Fynix v2 events and scoped service credentials. Do not share databases or application tokens. Changes to an event contract require compatible receivers before publishers are enabled. CyberAudit ↔ ITSM activation details live in CyberAudit's `docs/deployment/grc-itsm-one-dc.md`.

## Required handoff

Record the deployed commit, workflow URL/result, migration result, health result, rollback artifact, and any configuration keys added (names only, never values). If deployment did not complete, say so explicitly.

## This repository

The workflow builds the Laravel production image, creates an immutable source release, and runs `deploy/aws-update.sh /opt/fynix-suite/cyberaudit <sha>`.

Production route: `https://cyberaudit.fynixhq.com/`. Integration readiness: `https://cyberaudit.fynixhq.com/api/suite/ready`. Run `php artisan migrate --force` and `php artisan fynix:suite-preflight` in the deployed app. The one-DC ITSM integration procedure is `docs/deployment/grc-itsm-one-dc.md`.

