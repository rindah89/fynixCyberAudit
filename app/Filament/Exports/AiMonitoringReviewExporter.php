<?php

namespace App\Filament\Exports;

use App\Models\AiMonitoringReview;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AiMonitoringReviewExporter extends Exporter
{
    protected static ?string $model = AiMonitoringReview::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('useCase.name')->label('Use Case'),
            ExportColumn::make('reviewer.name')->label('Reviewed By'),
            ExportColumn::make('reviewed_at'),
            ExportColumn::make('outcome')->formatStateUsing(fn ($state) => $state->getLabel()),
            ExportColumn::make('performance_summary'),
            ExportColumn::make('incidents_count'),
            ExportColumn::make('complaints_count'),
            ExportColumn::make('assessment_version'),
            ExportColumn::make('governance_fingerprint'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['useCase:id,name', 'reviewer:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your AI monitoring-review export completed with '.number_format($export->successful_rows).' successful rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
