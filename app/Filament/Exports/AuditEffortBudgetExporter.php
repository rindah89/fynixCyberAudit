<?php

namespace App\Filament\Exports;

use App\Models\AuditEffortBudget;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AuditEffortBudgetExporter extends Exporter
{
    protected static ?string $model = AuditEffortBudget::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('procedure.code'), ExportColumn::make('user.name'), ExportColumn::make('version'),
            ExportColumn::make('planned_minutes'), ExportColumn::make('rationale'),
            ExportColumn::make('allocation_snapshot')->state(fn (AuditEffortBudget $record): string => json_encode($record->allocation_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('setter.name'), ExportColumn::make('set_at'), ExportColumn::make('fingerprint'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['procedure:id,code', 'user:id,name', 'setter:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your audit effort budget export has completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
