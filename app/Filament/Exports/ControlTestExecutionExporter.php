<?php

namespace App\Filament\Exports;

use App\Models\ControlTestExecution;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class ControlTestExecutionExporter extends Exporter
{
    protected static ?string $model = ControlTestExecution::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('definition.code')->label('Test Code'),
            ExportColumn::make('definition.name')->label('Test Name'),
            ExportColumn::make('executor.name')->label('Executed By'),
            ExportColumn::make('executed_at'),
            ExportColumn::make('metric_type'),
            ExportColumn::make('operator'),
            ExportColumn::make('expected_value'),
            ExportColumn::make('observed_value'),
            ExportColumn::make('outcome')->formatStateUsing(fn ($state) => $state->getLabel()),
            ExportColumn::make('result_reason'),
            ExportColumn::make('evidence_count')->label('Governed Evidence Files')
                ->state(fn (ControlTestExecution $record): int => $record->evidence->count()),
            ExportColumn::make('evidence_sha256')->label('Evidence SHA-256')
                ->state(fn (ControlTestExecution $record): string => $record->evidence->pluck('sha256')->implode(', ')),
            ExportColumn::make('evidence_audit_ids')->label('Evidence Audit IDs')
                ->state(fn (ControlTestExecution $record): string => $record->evidence->pluck('audit_id_snapshot')->implode(', ')),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['definition:id,code,name', 'executor:id,name', 'evidence']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your control-test execution export completed with '.number_format($export->successful_rows).' successful rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
