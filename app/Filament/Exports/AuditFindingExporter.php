<?php

namespace App\Filament\Exports;

use App\Models\AuditFinding;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AuditFindingExporter extends Exporter
{
    protected static ?string $model = AuditFinding::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code'), ExportColumn::make('title'), ExportColumn::make('severity'),
            ExportColumn::make('condition'), ExportColumn::make('criteria'), ExportColumn::make('cause'), ExportColumn::make('effect'),
            ExportColumn::make('recommendation'), ExportColumn::make('accountableOwner.name'),
            ExportColumn::make('source_snapshot')->state(fn (AuditFinding $record): string => json_encode($record->source_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('raiser.name'), ExportColumn::make('raised_at'), ExportColumn::make('fingerprint'),
            ExportColumn::make('responses')->state(fn (AuditFinding $record): string => json_encode($record->responses->map->only(['version', 'position', 'response', 'action_plan', 'target_date', 'finding_snapshot', 'responded_by', 'responded_at', 'fingerprint'])->all(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('remediation')->state(fn (AuditFinding $record): ?string => $record->remediation ? json_encode($record->remediation->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['accountableOwner:id,name', 'raiser:id,name', 'responses' => fn ($query) => $query->orderBy('version'), 'remediation.task', 'remediation.followUps' => fn ($query) => $query->orderBy('version')]);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your governed audit finding export has completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
