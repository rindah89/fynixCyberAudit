<div class="space-y-3">
    @foreach ($evidence as $record)
        <div class="rounded-lg border border-gray-200 p-3">
            <a class="font-medium text-primary-600 hover:underline"
               href="{{ route('control-test-execution-evidence.download', $record) }}">
                {{ $record->file_name_snapshot }}
            </a>
            <div class="mt-1 text-sm text-gray-600">
                {{ number_format($record->file_size_snapshot) }} bytes · Audit {{ $record->audit_id_snapshot }} · SHA-256 {{ $record->sha256 }}
            </div>
        </div>
    @endforeach
</div>
