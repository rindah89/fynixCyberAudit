<?php

namespace App\Filament\Exports;

use App\Models\AuditableEntityAssessment;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AuditableEntityAssessmentExporter extends Exporter
{
    protected static ?string $model = AuditableEntityAssessment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('auditableEntity.code')->label('Entity Code'), ExportColumn::make('version'),
            ExportColumn::make('inherent_likelihood'), ExportColumn::make('inherent_impact'), ExportColumn::make('inherent_score'),
            ExportColumn::make('residual_likelihood'), ExportColumn::make('residual_impact'), ExportColumn::make('residual_score'),
            ExportColumn::make('priority_band'), ExportColumn::make('rationale'), ExportColumn::make('next_assessment_at'),
            ExportColumn::make('entity_snapshot')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
            ExportColumn::make('risk_snapshots')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
            ExportColumn::make('control_snapshots')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
            ExportColumn::make('governance_fingerprint'), ExportColumn::make('assessor.name')->label('Assessed By'), ExportColumn::make('assessed_at'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['auditableEntity:id,code', 'assessor:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your auditable-entity assessment export contains '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).'.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
