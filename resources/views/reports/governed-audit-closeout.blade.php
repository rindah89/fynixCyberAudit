<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Final Audit Report</title></head>
<body style="font-family: DejaVu Sans, sans-serif; font-size: 11px;">
<h1>Final Audit Report</h1>
<p><strong>Audit:</strong> {{ data_get($report, 'audit_snapshot.title') }}</p>
<p><strong>Opinion:</strong> {{ str_replace('_', ' ', data_get($report, 'opinion')) }}</p>
<p><strong>Independent decision:</strong> {{ data_get($report, 'decision') }}</p>
<h2>Executive summary</h2><p>{{ data_get($report, 'executive_summary') }}</p>
<h2>Scope limitations</h2><p>{{ data_get($report, 'scope_limitations') ?: 'None reported.' }}</p>
<h2>Significant matters</h2><p>{{ data_get($report, 'significant_matters') }}</p>
<h2>Recommendations</h2><p>{{ data_get($report, 'recommendations_summary') }}</p>
<h2>Governed work program</h2>
<p>{{ count(data_get($report, 'audit_procedure_snapshots', [])) }} procedure version(s) retained in the report snapshot.</p>
@foreach (data_get($report, 'audit_procedure_snapshots', []) as $procedure)
    <p><strong>{{ data_get($procedure, 'code') }} v{{ data_get($procedure, 'version') }}:</strong>
        {{ str_replace('_', ' ', data_get($procedure, 'execution.outcome', 'pending')) }} —
        {{ \Illuminate\Support\Str::limit(data_get($procedure, 'execution.result', 'No execution result'), 500) }}<br>
        <strong>Supervisory review:</strong> {{ str_replace('_', ' ', data_get($procedure, 'supervisory_review.decision', 'pending')) }} —
        {{ \Illuminate\Support\Str::limit(data_get($procedure, 'supervisory_review.review_summary', 'No review summary'), 500) }}</p>
@endforeach
<h2>Effort summary</h2>
<p><strong>Planned:</strong> {{ number_format(data_get($report, 'audit_effort_snapshots.summary.planned_minutes', 0)) }} minutes ·
    <strong>Actual:</strong> {{ number_format(data_get($report, 'audit_effort_snapshots.summary.actual_minutes', 0)) }} minutes ·
    <strong>Variance:</strong> {{ number_format(data_get($report, 'audit_effort_snapshots.summary.variance_minutes', 0)) }} minutes</p>
<h2>Findings and management responses</h2>
@forelse (data_get($report, 'audit_finding_snapshots', []) as $finding)
    <p><strong>{{ data_get($finding, 'code') }} · {{ ucfirst(data_get($finding, 'severity')) }} · {{ data_get($finding, 'title') }}</strong><br>
        {{ \Illuminate\Support\Str::limit(data_get($finding, 'condition'), 500) }}<br>
        <strong>Recommendation:</strong> {{ \Illuminate\Support\Str::limit(data_get($finding, 'recommendation'), 500) }}<br>
        <strong>Latest management position:</strong> {{ str_replace('_', ' ', data_get($finding, 'responses.'.(count(data_get($finding, 'responses', [])) - 1).'.position', 'none')) }}</p>
@empty
    <p>No governed findings were recorded.</p>
@endforelse
<h2>Independent review</h2><p>{{ data_get($report, 'review_summary') }}</p>
<p><strong>Submission fingerprint:</strong> {{ data_get($report, 'submission_fingerprint') }}</p>
<p><strong>Reviewed by user:</strong> {{ data_get($report, 'reviewed_by') }} at {{ data_get($report, 'reviewed_at') }}</p>
</body>
</html>
