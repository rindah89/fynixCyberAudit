<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Final Incident Report</title></head>
<body style="font-family: DejaVu Sans, sans-serif; font-size: 10px;">
<h1>Final Incident Report</h1>
<p><strong>Incident:</strong> {{ data_get($report, 'incident.number') }} — {{ data_get($report, 'incident.title') }}</p>
<p><strong>Severity / phase:</strong> {{ data_get($report, 'incident.severity') }} / {{ data_get($report, 'incident.phase') }}</p>
<h2>Executive summary</h2><p>{{ data_get($report, 'executive_summary') }}</p>
<h2>Conclusions</h2><p>{{ data_get($report, 'conclusions') }}</p>
<h2>Response phases</h2>
@foreach (data_get($report, 'phase_transitions', []) as $transition)
<p><strong>{{ data_get($transition, 'from_phase', 'Created') }} → {{ data_get($transition, 'to_phase') }}</strong> — {{ data_get($transition, 'summary') }}</p>
@endforeach
<h2>Tasks by phase</h2>
@foreach (collect(data_get($report, 'tasks', []))->groupBy('phase') as $phase => $tasks)
<h3>{{ $phase }}</h3>
@foreach ($tasks as $task)<p>{{ data_get($task, 'title') }} — {{ data_get($task, 'status') }} · {{ data_get($task, 'priority') }}</p>@endforeach
@endforeach
<h2>Evidence manifest</h2>
@forelse (data_get($report, 'evidence_manifest', []) as $evidence)
<p>{{ data_get($evidence, 'context_type') }} #{{ data_get($evidence, 'context_id') }} · {{ data_get($evidence, 'file_name_snapshot') }} · {{ data_get($evidence, 'file_size_snapshot') }} bytes<br><span style="font-family: monospace;">{{ data_get($evidence, 'sha256') }}</span></p>
@empty <p>No governed retained evidence was included.</p> @endforelse
<h2>Notification status</h2>
@forelse (data_get($report, 'notifications', []) as $notification)
<p>{{ data_get($notification, 'audience') }} — {{ data_get($notification, 'recipient') }}: {{ data_get($notification, 'status') }}</p>
@empty <p>No notification decisions were recorded.</p> @endforelse
<h2>Affected entities</h2>
@forelse (data_get($report, 'affected_entities', []) as $entity)
<p><strong>{{ data_get($entity, 'entity_type') }} #{{ data_get($entity, 'entity_id_snapshot') }}</strong> — {{ data_get($entity, 'impact_summary') }}</p>
@empty <p>No affected entities were recorded.</p> @endforelse
<h2>Auditor-visible timeline</h2>
@forelse (data_get($report, 'auditor_timeline', []) as $entry)
<p><strong>{{ data_get($entry, 'occurred_at') }} · {{ data_get($entry, 'entry_type') }}</strong> — {{ data_get($entry, 'summary') }}</p>
@empty <p>No auditor-visible manual timeline entries were recorded.</p> @endforelse
<h2>Lessons learned</h2>
@forelse (data_get($report, 'lessons', []) as $lesson)
<p><strong>{{ data_get($lesson, 'area') }} · {{ data_get($lesson, 'status') }}</strong> — {{ data_get($lesson, 'observation') }}<br>Recommendation: {{ data_get($lesson, 'recommendation') }}</p>
@empty <p>No governed lessons were recorded.</p> @endforelse
</body>
</html>
