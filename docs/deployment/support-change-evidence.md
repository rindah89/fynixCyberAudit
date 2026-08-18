# Support pre-mutation CyberAudit acceptance

This API is an independent security-evidence decision. It does not create,
approve, consume, or replace an ITSM change authorization.

Support submits an immutable `fynix-cyberaudit-acceptance-request/v2` request to
`POST /api/support-change-evidence` using the dedicated requester bearer. The
request binds the Executive HQ company/tenant/customer identity, exact Support
and DevOps revisions, immutable image digest, readiness and emission evidence,
ITSM binding digest, operation, purpose, and operation UUID. CyberAudit
recomputes the ITSM digest rather than trusting the caller. Reusing the same
company/producer/request UUID with changed evidence returns 409.
Each company/operation UUID is also unique.
The requester credential is configured for exactly one company; it cannot
submit, read, or consume another tenant's authorization.

A named human with `review support change evidence` must independently inspect
the evidence and call `POST /api/support-change-evidence/{id}/accept` through
their own Sanctum session. The reviewer needs both effective RBAC permission
and an active company-scoped reviewer assignment. Acceptance is valid for at
most ten minutes, but it is not execution evidence by itself. Support must call
the authenticated `POST /api/support-change-evidence/{id}/consume` boundary
with the exact purpose, operation UUID, and request digest. That transaction
consumes the decision once and returns an Ed25519-signed
`fynix-cyberaudit-acceptance/v2` receipt. Exact replay returns the same receipt;
another request or operation cannot reuse it. Support verifies the canonical
digest and detached signature with public keys only. A separate identity
with `revoke support change evidence` can revoke an accepted receipt; the
original reviewer cannot revoke their own decision.

Executive HQ bindings are stored as active, versioned authority records and
are revalidated on every read, decision, revocation, and consumption. A missing
or changed binding permanently revokes the pending authorization before it
fails closed; restoring the old mapping does not revive it. Rejection,
revocation, expiry, and consumption are terminal. Append-only audit rows and
restrictive foreign keys prevent accepted evidence from being deleted through
the application model.

Production provisioning must:

1. Derive company, suite tenant, and customer values from Executive HQ.
2. Materialize an independent requester secret, an Ed25519 private signing key,
   and an owner-only JSON public-key set under `/run`
   from the KMS-backed secret authority; never place values in `.env`.
3. Assign review and revoke permissions to separate named humans. Do not grant
   either permission to the Support machine actor.
4. Give Support only the requester credential and public verifier key set. Keep the
   CyberAudit signing producer and revocation authority out of Support.
5. Recreate the application after materialization, migrate, exercise pending,
   accept, verify, expire, and revoke paths in a non-production binding, then
   record the exact release and rollback artifact.

No acceptance may be synthesized during deployment. If no authorized reviewer
is available, the receipt remains pending and Support must fail closed.
During signing-key rotation, the public set contains exactly the current and
previous key IDs. The configured current public key must match the private key;
a mismatch prevents consumption.
