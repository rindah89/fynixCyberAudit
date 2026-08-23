# Continuous control testing foundation

Fynix Cyber Audit supports recurring, threshold-based control tests submitted by accountable operators or authenticated integrations. It does not claim autonomous control monitoring, external source connectors, statistical anomaly detection, or automatic remediation.

## Operator workflow

1. Open **Compliance → Control Testing**.
2. Create a test definition for a control, accountable owner, cadence, next run time, metric type, comparison operator, and expected value.
3. Optionally scope the definition to an implementation already mapped to that control.
4. Record a boolean or numeric observation, notes, and an optional external evidence reference.
5. Fynix derives the result from the stored threshold. Callers cannot submit or override the outcome.
6. Review append-only execution history. A failed result automatically opens a control-test finding assigned to the definition owner.
7. Route that finding through the shared [governance issue and remediation lifecycle](GOVERNANCE_ISSUE_LIFECYCLE.md) for deliberate remediation handoff, independent verification, closure, and reopening.

The schedule communicates when another observation is due; it does not run a connector or collect source-system data by itself. Recurring schedules advance from the execution timestamp.

## Thresholds

Numeric tests support `equals`, `not_equals`, `greater_than`, `greater_than_or_equal`, `less_than`, and `less_than_or_equal`. Boolean tests accept `true` or `false` with `equals` or `not_equals`.

Numeric values use decimal-safe comparison and accept up to 15 integer digits and 6 decimal places; exponential notation is not accepted.

`passed` means the observation met the configured comparison. `failed` means it did not. Each immutable execution snapshots the metric type, operator, expected value, and observed value used in the decision, so later definition edits do not rewrite historical meaning.

## Authorization and integrity

- Operators with `Update Controls` create definitions.
- The definition owner, control owner, or an operator with `Update Controls` records observations.
- Owners can discover their assigned definitions without broad control-list access; the workspace remains owner-scoped.
- Inactive definitions reject executions.
- An implementation scope must already be mapped to the selected control.
- Product interfaces append execution records and do not update or delete them. Database administrators remain outside this application-level guarantee.
- Every execution records the submitting actor and timestamp.
- Evidence references are unverified, operator-supplied text. They do not establish that evidence exists or grant access to another system.

## Integration interface

Create a definition:

`POST /api/controls/{control}/test-definitions`

Record an observation and derive a result:

`POST /api/control-test-definitions/{definition}/execute`

Control detail responses include definitions, owners, latest executions, and generated findings.

## Explicit limitations

This foundation does not schedule background execution, pull indicators from external systems, verify referenced evidence, aggregate samples, detect anomalies, automatically close findings, or automatically create remediation tasks. Generated control-test findings are separate from audit findings. Do not describe this feature as autonomous continuous monitoring or end-to-end continuous control testing automation.
