# Policy compliance workflow

Fynix Cyber Audit tracks policy compliance as attributable attestations against discrete policy obligations. This workflow does not claim automated regulatory-change management; control testing is documented separately in `docs/CONTINUOUS_CONTROL_TESTING.md`.

## Operator workflow

1. Open **Compliance → Policy Obligations**.
2. Create an obligation with a policy, accountable owner, unique code, cadence, and next due date. A related control is optional.
3. The accountable owner, the policy owner, or an operator with `Update Policies` submits an attestation.
4. Record an outcome, a required statement, and optionally an external evidence reference and policy exception.
5. Review the read-only attestation history on the obligation. A correction is a new attestation; existing attestations cannot be changed through product interfaces.

An evidence reference is operator-supplied text for locating a record in a data request, audit item, governed repository, or other evidence system. Fynix does not verify that the referenced record exists or grant access to it. It is not a file upload and does not bypass the referenced system's access rules.

## Compliance status

| Status | Meaning |
|---|---|
| `due` | Active obligation has not been attested and its due date has not passed. |
| `compliant` | Latest attestation is compliant and the next due date has not passed. |
| `non_compliant` | Latest attestation records a gap and the next due date has not passed. |
| `not_applicable` | Latest attestation records that the obligation does not apply. |
| `overdue` | The active obligation's next due date has passed, regardless of its previous outcome. |
| `inactive` | The obligation is not currently tracked. |

Recurring attestations calculate their next due date from the attestation time: monthly, quarterly, semi-annual, or annual. A one-time attestation clears the next due date.

## Authorization and integrity

- Policy editors create and maintain obligations.
- Assigned obligation owners and policy owners can discover their obligations without broad policy-list access; their workspace query remains owner-scoped.
- The obligation owner, policy owner, or an authorized policy editor may attest. Every attestation records the actual submitting actor.
- Unrelated users cannot attest.
- A linked policy exception must belong to the same policy and be approved and currently in effect.
- Product interfaces do not update or delete attestations; subsequent submissions append history. Database administrators remain outside this application-level guarantee.
- Inactive obligations cannot be attested.
- Obligations soft-delete. Attested obligations and referenced exceptions cannot be physically deleted while their attestation history exists.
- Each attestation records the user and timestamp.

## Integration interface

Create an obligation:

`POST /api/policies/{policy}/obligations`

Submit an attestation:

`POST /api/policy-obligations/{obligation}/attest`

Policy detail responses include obligations, owners, related controls, current derived status, and latest attestations.

## Explicit limitations

This workflow does not yet provide regulatory content feeds, automated policy-to-law change detection, or employee acknowledgement campaigns. Control-test results must not be inferred from policy attestations; they are recorded through the separate control-testing workflow.
