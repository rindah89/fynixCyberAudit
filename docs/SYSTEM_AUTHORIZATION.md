# Governed system authorization

Enable this foundation with `MODULE_SYSTEM_AUTHORIZATION_ENABLED=true`. It governs deliberate authorization packages for existing application records; it does not discover systems or continuously collect control telemetry.

## Roles and separation

- `Manage System Authorizations` submits packages and must also have current view access to the application and every selected control and risk.
- `Authorize Systems` records authorization decisions.
- `Read System Authorizations` inspects all retained packages and decisions.
- The application owner and package submitter cannot decide their package. Only the latest package version may be decided.

## Package evidence

Submission locks the application and selected governance records, allocates one of at most 100 application-scoped versions, and retains the complete application snapshot, boundary, impact level, data classifications, selected control and risk snapshots, deliberately listed open findings, monitoring strategy, optional POA&M reference, change summary, submitter/time, and SHA-256 fingerprint. Approval re-locks and compares the exact current application/control/risk context; changed or superseded packages require resubmission.

## Decisions and state

An independent authorizer records `Authorized`, `Authorized with conditions`, `Denied`, or a later `Revoked` decision. Conditional authorization requires conditions. Authorization requires a future validity date. Each immutable decision retains the complete package snapshot, rationale, conditions, authorizer/time, validity, and fingerprint. The derived state becomes `authorization_expired` after the validity date. A package permits at most 100 decisions, although the lifecycle normally contains one decision and an optional revocation.

REST maintenance is documented in `docs/API_DOCUMENTATION.md`; the operator workspace provides paginated read-only package and decision inspection plus authorizer decision entry. Routine rollback retains both tables.

## Limits

All package content, selections, findings, monitoring strategies, and decisions are deliberate user inputs. Fynix does not discover authorization boundaries, ingest telemetry, validate control operation, authenticate evidence, calculate security impact, execute POA&M work, automatically authorize/revoke systems, provide a qualified signature, or prove regulatory compliance. Periodic continuous-authorization monitoring reviews are a separate capability and are not delivered by this foundation.
