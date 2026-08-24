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
<h2>Independent review</h2><p>{{ data_get($report, 'review_summary') }}</p>
<p><strong>Submission fingerprint:</strong> {{ data_get($report, 'submission_fingerprint') }}</p>
<p><strong>Reviewed by user:</strong> {{ data_get($report, 'reviewed_by') }} at {{ data_get($report, 'reviewed_at') }}</p>
</body>
</html>
