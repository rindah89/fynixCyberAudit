<?php

namespace App\Filament\Exports;

use App\Models\AuditProcedure;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AuditProcedureExporter extends Exporter
{
    protected static ?string $model = AuditProcedure::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code'), ExportColumn::make('version'), ExportColumn::make('title'), ExportColumn::make('objective'),
            ExportColumn::make('steps'), ExportColumn::make('method'), ExportColumn::make('population_description'),
            ExportColumn::make('planned_sample_size'), ExportColumn::make('assignee.name'), ExportColumn::make('due_at'),
            ExportColumn::make('status'), ExportColumn::make('creator.name'), ExportColumn::make('created_at'),
            ExportColumn::make('execution.outcome'), ExportColumn::make('execution.result'), ExportColumn::make('execution.exceptions'),
            ExportColumn::make('execution.sample_tested'), ExportColumn::make('execution.evidence_reference'),
            ExportColumn::make('execution.procedure_snapshot')->state(fn (AuditProcedure $record): string => json_encode($record->execution?->procedure_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('execution.executor.name'), ExportColumn::make('execution.executed_at'), ExportColumn::make('execution.fingerprint'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['assignee' => fn ($query) => $query->withTrashed(), 'creator' => fn ($query) => $query->withTrashed(), 'execution.executor' => fn ($query) => $query->withTrashed()]);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your audit procedure export has completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
