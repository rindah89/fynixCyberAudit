<?php

namespace App\Filament\Exports;

use App\Models\VendorFourthPartyDependency;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class VendorFourthPartyDependencyExporter extends Exporter
{
    protected static ?string $model = VendorFourthPartyDependency::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('vendor.name')->label('Primary Vendor'),
            ExportColumn::make('fourth_party_name')->label('Fourth Party'),
            ExportColumn::make('version'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('category')->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('criticality')->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('businessService.code')->label('Business Service'),
            ExportColumn::make('service_description'),
            ExportColumn::make('data_access'),
            ExportColumn::make('source_reference'),
            ExportColumn::make('rationale'),
            ExportColumn::make('governance_snapshot')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
            ExportColumn::make('recorder.name')->label('Recorded By'),
            ExportColumn::make('recorded_at'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['vendor:id,name', 'businessService:id,code,name', 'recorder:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your fourth-party dependency export completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
