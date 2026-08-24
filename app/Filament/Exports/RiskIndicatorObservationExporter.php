<?php

namespace App\Filament\Exports;

use App\Models\RiskIndicatorObservation;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class RiskIndicatorObservationExporter extends Exporter
{
    protected static ?string $model = RiskIndicatorObservation::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('indicator.code'), ExportColumn::make('indicator.name'), ExportColumn::make('observed_value'), ExportColumn::make('unit_snapshot'),
            ExportColumn::make('direction_snapshot'), ExportColumn::make('warning_threshold_snapshot'), ExportColumn::make('critical_threshold_snapshot'),
            ExportColumn::make('status'), ExportColumn::make('reason'), ExportColumn::make('notes'), ExportColumn::make('source_reference'),
            ExportColumn::make('observer.name'), ExportColumn::make('observed_at'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['indicator:id,code,name', 'observer:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return __('Your risk indicator observation export is ready.');
    }
}
