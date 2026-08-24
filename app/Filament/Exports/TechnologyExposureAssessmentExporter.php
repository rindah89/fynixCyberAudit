<?php

namespace App\Filament\Exports;

use App\Models\TechnologyExposureAssessment;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class TechnologyExposureAssessmentExporter extends Exporter
{
    protected static ?string $model = TechnologyExposureAssessment::class;

    public static function getColumns(): array
    {
        return [ExportColumn::make('version'), ExportColumn::make('exposure_type'), ExportColumn::make('title'), ExportColumn::make('asset_snapshot.asset_tag'), ExportColumn::make('asset_snapshot.name'), ExportColumn::make('threat_scenario'), ExportColumn::make('vulnerability_reference'), ExportColumn::make('vulnerability_description'), ExportColumn::make('source_reference'), ExportColumn::make('inherent_likelihood'), ExportColumn::make('inherent_impact'), ExportColumn::make('inherent_score'), ExportColumn::make('residual_likelihood'), ExportColumn::make('residual_impact'), ExportColumn::make('residual_score'), ExportColumn::make('appetite_threshold_snapshot'), ExportColumn::make('state'), ExportColumn::make('recommended_response'), ExportColumn::make('review_due_at'), ExportColumn::make('asset_snapshot_json'), ExportColumn::make('governance_snapshot_json'), ExportColumn::make('governance_fingerprint'), ExportColumn::make('assessor.name'), ExportColumn::make('assessed_at')];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with('assessor:id,name');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return __('Your technology exposure assessment export is ready.');
    }
}
