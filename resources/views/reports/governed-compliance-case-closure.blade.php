<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Compliance Case Closure Report</title></head>
<body style="font-family: DejaVu Sans, sans-serif; font-size: 10px;">
<h1>Compliance Case Closure Report</h1>
<p><strong>Case:</strong> {{ data_get($report, 'case.case.number') }} — {{ data_get($report, 'case.case.title') }}</p>
<p><strong>Status:</strong> {{ data_get($report, 'case.case.status') }}</p>
<h2>Executive summary</h2><p>{{ data_get($report, 'executive_summary') }}</p>
<h2>Resolution and closure</h2>
<p><strong>Resolution:</strong> {{ data_get($report, 'case.case.resolution_summary') }}</p>
<p><strong>Closure:</strong> {{ data_get($report, 'case.case.closure_summary') }}</p>
<h2>Approved investigation report</h2>
<p><strong>Outcome:</strong> {{ data_get($report, 'approved_investigation_report.outcome') }}</p>
<p>{{ data_get($report, 'approved_investigation_report.executive_summary') }}</p>
<h2>Governed case history</h2>
@foreach (data_get($report, 'events', []) as $event)
<p><strong>v{{ data_get($event, 'version') }} · {{ data_get($event, 'event_type') }}</strong> — {{ data_get($event, 'summary') }}</p>
@endforeach
<h2>Governed-source counts</h2>
<p>Evidence submissions: {{ data_get($report, 'counts.evidence_submissions', 0) }} · Interviews: {{ data_get($report, 'counts.interviews', 0) }} · Legal holds: {{ data_get($report, 'counts.legal_holds', 0) }} · Action issues: {{ data_get($report, 'counts.action_issues', 0) }}</p>
</body>
</html>
