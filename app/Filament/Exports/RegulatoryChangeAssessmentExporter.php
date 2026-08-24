<?php

namespace App\Filament\Exports;

use App\Models\RegulatoryChangeAssessment;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class RegulatoryChangeAssessmentExporter extends Exporter
{
    protected static ?string $model = RegulatoryChangeAssessment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('requirementVersion.requirement.code')->label('Requirement Code'),
            ExportColumn::make('requirementVersion.version')->label('Requirement Version'),
            ExportColumn::make('assessment_version'),
            ExportColumn::make('applicability')->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('impact')->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('summary'), ExportColumn::make('rationale'),
            ExportColumn::make('actionOwner.name')->label('Action Owner'), ExportColumn::make('action_due_at'),
            ExportColumn::make('requirement_snapshot')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
            ExportColumn::make('policy_snapshots')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
            ExportColumn::make('control_snapshots')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
            ExportColumn::make('content_fingerprint'), ExportColumn::make('assessor.name')->label('Assessed By'),
            ExportColumn::make('assessed_at'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['requirementVersion.requirement:id,code', 'assessor:id,name', 'actionOwner:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your regulatory change-assessment export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
