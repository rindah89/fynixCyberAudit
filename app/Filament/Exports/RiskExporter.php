<?php

namespace App\Filament\Exports;

use Aliziodev\LaravelTaxonomy\Models\Taxonomy;
use App\Models\Risk;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class RiskExporter extends Exporter
{
    protected static ?string $model = Risk::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code')
                ->label('Code'),
            ExportColumn::make('name')
                ->label('Name'),
            ExportColumn::make('description')
                ->label('Description'),
            ExportColumn::make('domain')
                ->label('Risk Domain')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('inherent_likelihood')
                ->label('Inherent Likelihood'),
            ExportColumn::make('inherent_impact')
                ->label('Inherent Impact'),
            ExportColumn::make('inherent_risk')
                ->label('Inherent Risk'),
            ExportColumn::make('residual_likelihood')
                ->label('Residual Likelihood'),
            ExportColumn::make('residual_impact')
                ->label('Residual Impact'),
            ExportColumn::make('residual_risk')
                ->label('Residual Risk'),
            ExportColumn::make('department')
                ->label('Department')
                ->state(function (Risk $record): ?string {
                    $parent = Taxonomy::where('slug', 'department')->whereNull('parent_id')->first();
                    if (! $parent) {
                        return null;
                    }

                    return $record->taxonomies()->where('parent_id', $parent->id)->first()?->name;
                }),
            ExportColumn::make('scope')
                ->label('Scope')
                ->state(function (Risk $record): ?string {
                    $parent = Taxonomy::where('slug', 'scope')->whereNull('parent_id')->first();
                    if (! $parent) {
                        return null;
                    }

                    return $record->taxonomies()->where('parent_id', $parent->id)->first()?->name;
                }),
            ExportColumn::make('is_active')
                ->label('Active'),
            ExportColumn::make('created_at')
                ->label('Created At'),
            ExportColumn::make('updated_at')
                ->label('Updated At'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your risk export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
