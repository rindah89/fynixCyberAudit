<?php

namespace App\Filament\Exports;

use App\Models\AuditTimeEntry;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AuditTimeEntryExporter extends Exporter
{
    protected static ?string $model = AuditTimeEntry::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('entry_type'), ExportColumn::make('work_date'), ExportColumn::make('minutes'),
            ExportColumn::make('activity'), ExportColumn::make('notes'), ExportColumn::make('source_reference'),
            ExportColumn::make('procedure.code'), ExportColumn::make('user.name'), ExportColumn::make('entrant.name'),
            ExportColumn::make('reverses_time_entry_id'),
            ExportColumn::make('budget_snapshot')->state(fn (AuditTimeEntry $record): string => json_encode($record->budget_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('procedure_snapshot')->state(fn (AuditTimeEntry $record): string => json_encode($record->procedure_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('entered_at'), ExportColumn::make('fingerprint'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['procedure:id,code', 'user:id,name', 'entrant:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your audit time-entry export has completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
