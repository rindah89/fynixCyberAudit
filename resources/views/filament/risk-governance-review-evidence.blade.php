<div class="space-y-3">
    @foreach ($review->evidence as $record)
        @if ($actor && $record->attachment && app(\App\Access\FileAccess::class)->canDownloadFileAttachment($actor, $record->attachment))
            <div class="rounded-lg border border-gray-200 p-4 text-sm">
                <a class="font-medium text-primary-600 hover:underline"
                   href="{{ route('risk-governance-review-evidence.download', $record) }}">
                    {{ $record->file_name_snapshot }}
                </a>
                <div class="mt-1 text-gray-600">
                    {{ number_format($record->file_size_snapshot) }} bytes · Audit {{ $record->audit_id_snapshot }} · SHA-256 {{ $record->sha256 }}
                </div>
            </div>
        @endif
    @endforeach
</div>
