<div class="space-y-4">
    @foreach ($reviews as $review)
        <div class="rounded-lg border border-gray-200 p-4">
            <div class="font-medium text-gray-950">
                {{ $review->outcome->getLabel() }} · {{ $review->reviewed_at->format('Y-m-d H:i') }} · {{ $review->reviewer->name }}
            </div>
            <p class="mt-2 text-sm text-gray-700">{{ $review->performance_summary }}</p>
            <div class="mt-2 text-sm text-gray-600">
                Incidents: {{ number_format($review->incidents_count) }} · Complaints: {{ number_format($review->complaints_count) }}
            </div>
            @foreach ($review->evidence as $record)
                <div class="mt-3 border-t border-gray-100 pt-3 text-sm">
                    @if ($actor && $record->attachment && app(\App\Access\FileAccess::class)->canDownloadFileAttachment($actor, $record->attachment))
                        <a class="font-medium text-primary-600 hover:underline"
                           href="{{ route('ai-monitoring-review-evidence.download', $record) }}">
                            {{ $record->file_name_snapshot }}
                        </a>
                        <div class="mt-1 text-gray-600">
                            {{ number_format($record->file_size_snapshot) }} bytes · Audit {{ $record->audit_id_snapshot }} · SHA-256 {{ $record->sha256 }}
                        </div>
                    @else
                        <span class="text-gray-600">Governed evidence metadata is restricted by its audit-file access policy.</span>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</div>
