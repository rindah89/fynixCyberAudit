<?php

namespace App\Filament\Exports;

use App\Models\Vendor;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class VendorExporter extends Exporter
{
    protected static ?string $model = Vendor::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name')
                ->label('Name'),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('risk_rating')
                ->label('Risk Rating')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('risk_score')
                ->label('Risk Score'),
            ExportColumn::make('third_party_risk_status')
                ->label('Third-Party Risk Status'),
            ExportColumn::make('latestRiskAssessment.version')
                ->label('Latest Assessment Version'),
            ExportColumn::make('latestRiskAssessment.residual_score')
                ->label('Latest Residual Score'),
            ExportColumn::make('latestRiskDecision.decision')
                ->label('Latest Risk Decision')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('latestRiskDecision.next_review_at')
                ->label('Next Risk Review'),
            ExportColumn::make('risks_count')
                ->label('Linked Third-Party Risks'),
            ExportColumn::make('vendorManager.name')
                ->label('Vendor Manager'),
            ExportColumn::make('contact_name')
                ->label('Contact Name'),
            ExportColumn::make('contact_email')
                ->label('Contact Email'),
            ExportColumn::make('contact_phone')
                ->label('Contact Phone'),
            ExportColumn::make('url')
                ->label('URL'),
            ExportColumn::make('address')
                ->label('Address'),
            ExportColumn::make('description')
                ->label('Description'),
            ExportColumn::make('notes')
                ->label('Notes'),
            ExportColumn::make('created_at')
                ->label('Created At'),
            ExportColumn::make('updated_at')
                ->label('Updated At'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->withThirdPartyRiskGraph()->with(['vendorManager' => fn ($query) => $query->withTrashed()])->withCount('risks');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your vendor export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

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
