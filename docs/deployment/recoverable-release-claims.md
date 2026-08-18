# Recoverable release claims

CyberAudit v3 evidence claims use a closed request containing `purpose`,
`nonce`, `ttl_seconds`, `request_digest`, and a caller-generated `claim_token`.
The token is exactly 32 secret bytes encoded as 64 lowercase hexadecimal
characters. CyberAudit stores only its SHA-256 digest and never returns the
token.

An exact retry with the same authorization, nonce, token, request digest, and
TTL returns the original claim metadata. A changed token, nonce, subject, or TTL
returns 409, as does a second active claim. Exact consume replay continues to
require the original token and operation UUID. Revocation, expiry, requester-key
lifecycle changes, and Executive HQ binding changes remain fail-closed.

The governed deployment adapter derives stable domain-separated claim tokens
from each service credential plus the authorization, nonce, and immutable
subject digest. Tokens are sent only in HTTPS request bodies and are never
written to release receipts, command arguments, logs, artifacts, or SSM output.
This permits a dispatcher restart to repeat claim and consume safely after any
cross-service interruption.

The server temporarily also accepts the prior four-field claim request and
returns its generated token so existing dispatchers remain operational while
the servers are deployed first. New dispatchers must use the recoverable form;
removing compatibility requires a separately versioned rollout after every
consumer has migrated.
