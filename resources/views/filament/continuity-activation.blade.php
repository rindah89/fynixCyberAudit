<div class="space-y-4 text-sm">
    <div><strong>Disruption:</strong> {{ $activation->disruption_summary }}</div>
    <div><strong>Business impact:</strong> {{ $activation->business_impact }}</div>
    <div><strong>Plan snapshot:</strong> {{ $activation->plan_snapshot['title'] }} v{{ $activation->plan_snapshot['version'] }}</div>
    <div><strong>Objectives:</strong> RTO {{ $activation->service_snapshot['impact_analysis']['recovery_time_objective_minutes'] }} min / RPO {{ $activation->service_snapshot['impact_analysis']['recovery_point_objective_minutes'] }} min</div>
    <div class="space-y-3">
        @foreach ($activation->events as $event)
            <div class="rounded-lg border border-gray-200 p-3">
                <div class="font-medium">v{{ $event->version }} · {{ $event->to_status->getLabel() }}</div>
                <div>{{ $event->summary }}</div>
                <div class="text-gray-500">{{ $event->recorder?->name }} · {{ $event->recorded_at?->toDayDateTimeString() }} · SHA-256 {{ $event->fingerprint }}</div>
            </div>
        @endforeach
    </div>
</div>
