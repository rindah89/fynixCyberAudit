<?php

namespace App\Filament\Exports;

use App\Models\OperationalLossEvent;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class OperationalLossEventExporter extends Exporter
{
    protected static ?string $model = OperationalLossEvent::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('risk.code')->label('Risk Code'),
            ExportColumn::make('business_service_snapshot.code')->label('Business Service'),
            ExportColumn::make('category')->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('occurred_at'),
            ExportColumn::make('detected_at'),
            ExportColumn::make('gross_loss'),
            ExportColumn::make('recoveries'),
            ExportColumn::make('net_loss'),
            ExportColumn::make('currency'),
            ExportColumn::make('summary'),
            ExportColumn::make('source_reference'),
            ExportColumn::make('reporter.name')->label('Reported By'),
            ExportColumn::make('recorded_at'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['risk:id,code', 'businessService:id,code', 'reporter:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your operational loss-event export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
